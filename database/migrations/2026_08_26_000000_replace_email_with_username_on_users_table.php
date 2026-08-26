<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The login screen asks for a username, so accounts are keyed by one instead of
 * an email address. Nothing in this app mails users or verifies addresses, so
 * "email_verified_at" goes out with the column it belonged to.
 *
 * The stock "password_reset_tokens" table is still keyed by email. It is left
 * alone: no route in this app touches it, and it holds no rows worth migrating.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the unique before the rename. MySQL carries the index across a
        // rename but keeps its old name, which would leave "users_email_unique"
        // sitting on a "username" column for down() to trip over.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('email', 'username');
        });

        $this->collapseAddressesToHandles();

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
            $table->dropColumn('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('username', 'email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }

    /**
     * Existing rows hold email addresses in what is now the username column.
     * Reduce each to its local part -- "admin@fortresssteel.local" becomes
     * "admin" -- so the accounts stay signable-in-to with what people already
     * type. Any row whose handle is already taken keeps its full address rather
     * than colliding on the unique added right after this runs.
     */
    private function collapseAddressesToHandles(): void
    {
        $users = DB::table('users')->orderBy('id')->get(['id', 'username']);

        foreach ($users as $user) {
            $handle = Str::before((string) $user->username, '@');

            if ($handle === '' || $handle === $user->username) {
                continue;
            }

            if (DB::table('users')->where('username', $handle)->exists()) {
                continue;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $handle]);
        }
    }
};
