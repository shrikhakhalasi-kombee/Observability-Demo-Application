<?php

namespace App\View\Components\Layouts;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class App extends Component
{
    public function __construct(
        public string $title = 'Dashboard',
    ) {}

    public function render(): View|Closure|string
    {
        return view('layouts.app');
    }
}
