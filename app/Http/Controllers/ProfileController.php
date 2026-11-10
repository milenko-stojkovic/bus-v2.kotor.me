<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\UiText;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $request->validateWithBag('userDeletion', [
            'delete_password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        try {
            // One transaction covers the user DELETE (including MySQL ON DELETE SET NULL)
            // and the deleted-event attribution cleanup. A cleanup failure rolls the account back.
            $deleted = DB::transaction(fn () => $user->delete());
        } catch (QueryException $e) {
            report($e);

            Auth::login($user);

            throw $this->deleteAccountBlockedException();
        }

        if ($deleted === false) {
            Auth::login($user);

            throw $this->deleteAccountBlockedException();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($request->root());
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
