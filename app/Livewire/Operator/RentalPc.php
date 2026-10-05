<?php

namespace App\Livewire\Operator;

use App\Models\TipeKonsol;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/** Menu kasir "Rental PC": halaman Rental untuk unit bertipe PC (kartu, remote & aksi PC) */
#[Layout('layouts.operator')]
#[Title('Rental PC')]
class RentalPc extends Rental
{
    public string $jenis = TipeKonsol::JENIS_PC;
}
