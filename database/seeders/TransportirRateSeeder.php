<?php

namespace Database\Seeders;

use App\Models\Transportir;
use App\Models\TransportirRate;
use Illuminate\Database\Seeder;

/**
 * BALI-10: haulage rates quoted in the Bali meeting note.
 *
 *   PT Raja Sumber Cahaya — 5.5 kg Rp3.500; 12 kg Rp5.000; 50 kg Rp10.000
 *   PT Mahardika         — 5.5 kg Rp4.000; 12 kg Rp5.000; 50 kg Rp10.000
 *
 * ⚠️ PENDING CLIENT CONFIRMATION — the amounts come from the meeting note and
 * were not formally confirmed. Rates are effective-dated, so correcting them
 * only requires adding a new row with the confirmed date/amount; nothing that
 * was already priced changes.
 *
 * Opt-in: `php artisan db:seed --class=TransportirRateSeeder`.
 */
class TransportirRateSeeder extends Seeder
{
    /** Note amounts, keyed by transportir name (as stored after BALI-15). */
    private const RATES = [
        'PT Raja Sumber Cahaya' => ['5.5kg' => 3500, '12kg' => 5000, '50kg' => 10000],
        'PT Mahardika'          => ['5.5kg' => 4000, '12kg' => 5000, '50kg' => 10000],
    ];

    public function run(): void
    {
        foreach (self::RATES as $name => $rates) {
            $transportir = Transportir::where('name', $name)->first();

            if (! $transportir) {
                continue;
            }

            foreach ($rates as $cylinderType => $rate) {
                TransportirRate::firstOrCreate(
                    [
                        'transportir_id' => $transportir->id,
                        'cylinder_type'  => $cylinderType,
                        'effective_date' => today()->toDateString(),
                    ],
                    ['rate' => $rate]
                );
            }
        }
    }
}
