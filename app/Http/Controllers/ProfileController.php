<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\UiText;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Agency panel: user profile (top navigation target /panel/user).
     */
    public function panel(Request $request): View
    {
        $deletionErrors = session('errors')?->getBag('userDeletion');
        if ($deletionErrors !== null && $deletionErrors->isNotEmpty()) {
            $this->logAccountDelete($request, 'panel_loaded_with_errors', $request->user(), [
                'errors' => $deletionErrors->all(),
            ]);
        }

        return view('panel.user', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $plainPassword = $validated['password'] ?? null;
        $payload = Arr::only($validated, ['name', 'email', 'lang', 'country']);

        $user->fill($payload);

        $emailChanged = $user->isDirty('email');
        $langChanged = $user->isDirty('lang');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        if (filled($plainPassword)) {
            $user->password = $plainPassword;
        }

        $user->save();

        if ($langChanged && $request->hasSession()) {
            $request->session()->put('locale', $user->lang);
        }

        if ($emailChanged && $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }

        return Redirect::route('panel.user')->with('status', 'profile_updated');
    }

    /**
     * Delete the user's account.
     *
     * Uses delete_password (not password) to avoid clashing with the profile form's
     * new-password field on the same /panel/user page.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->logAccountDelete($request, 'started', $user, [
            'has_delete_password' => $request->has('delete_password'),
            'delete_password_length' => mb_strlen((string) $request->input('delete_password', '')),
            'method' => $request->method(),
            'spoofed_method' => $request->input('_method'),
        ]);

        try {
            $request->validateWithBag('userDeletion', [
                'delete_password' => ['required', 'current_password'],
            ]);
        } catch (ValidationException $e) {
            $this->logAccountDelete($request, 'validation_failed', $user, [
                'errors' => $e->errors(),
            ]);

            throw $e;
        }

        $this->logAccountDelete($request, 'validation_passed', $user);

        Auth::logout();

        $this->logAccountDelete($request, 'logged_out_before_delete', $user);

        try {
            $deleted = $user->delete();
        } catch (QueryException $e) {
            report($e);

            $this->logAccountDelete($request, 'delete_query_exception', $user, [
                'sql_state' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
                'message' => $e->getMessage(),
            ]);

            Auth::login($user);

            $this->logAccountDelete($request, 'relogged_in_after_failure', $user);

            throw $this->deleteAccountBlockedException();
        }

        $this->logAccountDelete($request, 'delete_attempt_finished', $user, [
            'deleted' => $deleted,
            'user_still_exists' => $user->exists,
        ]);

        if ($deleted === false) {
            Auth::login($user);

            $this->logAccountDelete($request, 'relogged_in_after_false_delete', $user);

            throw $this->deleteAccountBlockedException();
        }

        $this->logAccountDelete($request, 'success_invalidating_session', $user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($request->root());
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logAccountDelete(Request $request, string $phase, ?\App\Models\User $user = null, array $context = []): void
    {
        Log::info('profile.account_delete', array_merge([
            'phase' => $phase,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'auth_check' => Auth::check(),
            'auth_id' => Auth::id(),
            'ip' => $request->ip(),
            'session_id' => $request->session()->getId(),
        ], $context));
    }

    private function deleteAccountBlockedException(): ValidationException
    {
        return ValidationException::withMessages([
            'delete_password' => [
                UiText::t(
                    'user',
                    'delete_account_blocked',
                    'Nalog se trenutno ne može obrisati zbog povezanih podataka. Kontaktirajte podršku.',
                ),
            ],
        ])->errorBag('userDeletion');
    }
}
