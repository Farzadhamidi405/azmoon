<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$shifts = App\Models\Shift::all();
echo "Shifts count: " . $shifts->count() . PHP_EOL;
foreach($shifts as $s) {
    echo "ID:{$s->id} Name:{$s->name} Lab:{$s->lab_id}" . PHP_EOL;
}
