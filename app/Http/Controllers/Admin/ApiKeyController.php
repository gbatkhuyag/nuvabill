<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Your profile → API keys: keys for the REST API that act as you.
 */
class ApiKeyController extends Controller
{
    /**
     * A new key is a lasting way back in, so it asks for the password like passkeys and two-factor
     * setup do (and shares their staff-reauth limit): a stolen session cannot quietly make one.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:admin'],
            'name' => ['required', 'string', 'max:60'],
            'can_write' => ['sometimes', 'boolean'],
        ]);

        $admin = $request->user('admin');
        [$token, $plain] = ApiToken::issue($admin, $data['name'], $request->boolean('can_write'));
        Activity::log('api.key_created', "API key \"{$token->name}\" created (".($token->can_write ? 'read and write' : 'read only').')', actor: $admin);

        // Flashed for the next page only, and encrypted, so the plain key is never in the session store.
        return back()->with('new_api_key', Crypt::encryptString($plain))->with('status', __('API key created. Copy it now: it is shown only once.'));
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        abort_unless($apiToken->admin_id === $request->user('admin')->id, 404);

        $apiToken->delete();
        Activity::log('api.key_deleted', "API key \"{$apiToken->name}\" deleted");

        return back()->with('status', __('API key deleted. Anything using it stops working now.'));
    }
}
