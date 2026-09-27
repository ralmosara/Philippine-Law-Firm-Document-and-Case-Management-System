<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Each staff member's private channel: live notifications for the bell.
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->id === $id && $user->is_active);
