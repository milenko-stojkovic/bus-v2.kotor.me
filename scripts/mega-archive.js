/**
 * Server-side MEGA helper for Bus Kotor (upload/download under one base folder).
 * Credentials: MEGA_EMAIL, MEGA_PASSWORD, MEGA_BASE_FOLDER (env).
 * stdin: JSON payload. stdout: single-line JSON result.
 */
import { createReadStream, createWriteStream, existsSync, mkdirSync } from 'fs';
import { statSync } from 'fs';
import { dirname } from 'path';
import { Storage } from 'megajs';

function readStdin() {
    return new Promise((resolve) => {
        let data = '';
        process.stdin.setEncoding('utf8');
        process.stdin.on('data', (chunk) => {
            data += chunk;
        });
        process.stdin.on('end', () => resolve(data));
    });
}

function sendResult(obj) {
    process.stdout.write(JSON.stringify(obj));
}

async function getStorage() {
    const email = process.env.MEGA_EMAIL || '';
    const password = process.env.MEGA_PASSWORD || '';
    if (!email || !password) {
        throw new Error('MEGA_EMAIL and MEGA_PASSWORD are required');
    }
    const storage = await new Storage({ email, password }).ready;
    return storage;
}

async function ensureBaseFolder(storage) {
    const name = process.env.MEGA_BASE_FOLDER || 'bus.kotor';
    let folder = storage.root.children.find((c) => c.directory && c.name === name);
    if (!folder) {
        folder = await storage.mkdir(name);
    }
    return { folder, name };
}

async function upload(payload) {
    const localPath = payload.localPath;
    const targetName = payload.targetName;
    if (!localPath || !targetName) {
        return { ok: false, error: 'localPath and targetName required' };
    }
    if (!existsSync(localPath)) {
        return { ok: false, error: 'local file missing' };
    }
    const storage = await getStorage();
    const { folder, name: baseName } = await ensureBaseFolder(storage);
    const st = statSync(localPath);
    const stream = createReadStream(localPath);
    const uploadTask = folder.upload({ name: targetName, size: st.size }, stream);
    const file = await uploadTask.complete;
    const nodeId =
        file?.node?.hash ||
        file?.node?.h ||
        file?.node?.k ||
        (typeof file?.node === 'string' ? file.node : null) ||
        null;
    const megaPath = `${baseName}/${targetName}`;
    return {
        ok: true,
        mega_node_id: nodeId != null ? String(nodeId) : null,
        mega_path: megaPath,
    };
}

async function download(payload) {
    const megaPath = payload.megaPath;
    const destAbsolutePath = payload.destAbsolutePath;
    const generatedFileName = payload.generatedFileName || payload.targetName;
    if (!destAbsolutePath) {
        return { ok: false, error: 'destAbsolutePath required' };
    }
    const storage = await getStorage();
    let file = null;
    if (megaPath) {
        file = storage.navigate(megaPath);
    }
    // Fallback: locate by file name under base folder (mega_path missing or stale)
    if ((!file || file.directory) && generatedFileName) {
        const { folder } = await ensureBaseFolder(storage);
        file = folder.children?.find((c) => !c.directory && c.name === generatedFileName) || null;
    }
    if (!file || file.directory) {
        return {
            ok: false,
            error: 'MEGA file not found: ' + (megaPath || generatedFileName || '(no path)'),
        };
    }
    mkdirSync(dirname(destAbsolutePath), { recursive: true });
    // megajs: download() returns a Readable. The optional callback receives (err, Buffer), not a stream — do not use .pipe on the callback argument.
    await new Promise((resolve, reject) => {
        const ws = createWriteStream(destAbsolutePath);
        const rs = file.download({});
        rs.on('error', reject);
        ws.on('error', reject);
        ws.on('finish', resolve);
        rs.pipe(ws);
    });
    const resolvedPath =
        megaPath ||
        `${process.env.MEGA_BASE_FOLDER || 'bus.kotor'}/${file.name || generatedFileName || ''}`;
    return { ok: true, mega_path: resolvedPath };
}

async function main() {
    const action = process.argv[2];
    const raw = await readStdin();
    let payload = {};
    try {
        payload = raw ? JSON.parse(raw) : {};
    } catch {
        sendResult({ ok: false, error: 'invalid stdin JSON' });
        process.exit(1);
        return;
    }
    try {
        let out;
        if (action === 'upload') {
            out = await upload(payload);
        } else if (action === 'download') {
            out = await download(payload);
        } else {
            out = { ok: false, error: 'unknown action: ' + String(action) };
        }
        sendResult(out);
        process.exit(out.ok ? 0 : 1);
    } catch (e) {
        sendResult({ ok: false, error: e && e.message ? String(e.message) : 'error' });
        process.exit(1);
    }
}

main();
