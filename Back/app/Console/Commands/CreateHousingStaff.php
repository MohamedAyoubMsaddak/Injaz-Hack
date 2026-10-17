<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateHousingStaff extends Command
{
    protected $signature = 'housing:create-staff {role : Staff role: admin or worker}';

    protected $description = 'Create an authorized housing administrator or governorate worker';

    public function handle(): int
    {
        $role = $this->argument('role');
        if (! in_array($role, ['admin', 'worker'], true)) {
            $this->error('Role must be admin or worker.');

            return self::FAILURE;
        }

        $attributes = [
            'name' => $this->ask('Full name'),
            'email' => $this->ask('Email address'),
            'phone' => $this->ask('Phone number'),
            'governorate' => $role === 'worker' ? $this->ask('Authorized governorate') : null,
        ];
        $password = $this->secret('Password (at least 12 characters)');
        $passwordConfirmation = $this->secret('Confirm password');

        $validated = Validator::make([
            ...$attributes,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'governorate' => [$role === 'worker' ? 'required' : 'nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validated->fails()) {
            foreach ($validated->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'phone' => $attributes['phone'],
            'password' => Hash::make($password),
        ]);
        $user->forceFill([
            'role' => $role,
            'governorate' => $attributes['governorate'],
        ])->save();

        $this->info(ucfirst($role).' account created for '.$user->email.'. The password was not displayed.');

        return self::SUCCESS;
    }
}
