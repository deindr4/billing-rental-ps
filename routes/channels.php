<?php

use Illuminate\Support\Facades\Broadcast;

// Id pengguna berupa UUID: bandingkan sebagai string (cast int membuat banyak UUID dianggap sama)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});
