<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class LocationAndPaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $warehouse = Location::updateOrCreate(
            ['code' => 'WH-01'],
            [
                'name' => 'Central Warehouse — Tejgaon',
                'type' => 'warehouse',
                'address' => 'Plot 12, Industrial Area, Tejgaon, Dhaka-1208',
                'phone' => '02-58111222',
                'manager_name' => 'Md. Kamal Hossain',
                'is_active' => true,
            ]
        );

        $branchDhaka = Location::updateOrCreate(
            ['code' => 'BR-01'],
            [
                'name' => 'Branch 1 — Dhanmondi Showroom',
                'type' => 'branch',
                'address' => 'House 42, Road 27 (Old), Dhanmondi R/A, Dhaka-1209',
                'phone' => '01711223344',
                'manager_name' => 'Fatema Begum',
                'is_active' => true,
            ]
        );

        $branchCtg = Location::updateOrCreate(
            ['code' => 'BR-CGP-01'],
            [
                'name' => 'Agrabad Sales Point',
                'type' => 'branch',
                'address' => 'Commercial Plot 6, Agrabad C/A, Chattogram-4100',
                'phone' => '031-7234567',
                'manager_name' => 'Tanvir Ahmed',
                'is_active' => true,
            ]
        );

        $globalMethods = [
            [
                'code' => 'PM-CASH',
                'name' => 'Cash',
                'type' => 'cash',
                'notes' => 'Default in-person cash; available everywhere unless a branch overrides.',
            ],
            [
                'code' => 'PM-NAGAD',
                'name' => 'Nagad',
                'type' => 'mobile_banking',
                'mobile_provider' => 'Nagad',
                'mobile_number' => '01700000001',
                'notes' => 'Placeholder wallet — replace in production.',
            ],
            [
                'code' => 'PM-ROCKET',
                'name' => 'Rocket (Dutch-Bangla)',
                'type' => 'mobile_banking',
                'mobile_provider' => 'Rocket',
                'mobile_number' => '01700000002',
                'notes' => 'Placeholder Rocket account.',
            ],
        ];

        foreach ($globalMethods as $row) {
            PaymentMethod::updateOrCreate(
                ['code' => $row['code']],
                array_merge($row, [
                    'location_id' => null,
                    'is_active' => true,
                    'created_by' => null,
                ])
            );
        }

        PaymentMethod::updateOrCreate(
            ['code' => 'PM-WH-BANK-SBL'],
            [
                'location_id' => $warehouse->id,
                'name' => 'Sonali Bank — Warehouse collection',
                'type' => 'bank',
                'account_name' => 'Inventory Demo Ltd.',
                'account_number' => '1234567890123',
                'bank_name' => 'Sonali Bank PLC',
                'bank_branch' => 'Tejgaon Industrial Area Branch',
                'routing_number' => '200260726',
                'swift_code' => null,
                'is_active' => true,
                'notes' => 'Incoming supplier / bulk settlement account.',
                'created_by' => null,
            ]
        );

        PaymentMethod::updateOrCreate(
            ['code' => 'PM-BR01-POS-SSL'],
            [
                'location_id' => $branchDhaka->id,
                'name' => 'SSLCommerz POS',
                'type' => 'card_terminal',
                'terminal_provider' => 'SSLCommerz',
                'terminal_id' => 'TERM-BR01-DEMO',
                'merchant_id' => 'MER-BR01-DEMO',
                'is_active' => true,
                'notes' => 'Card payments at BR-01 showroom.',
                'created_by' => null,
            ]
        );

        PaymentMethod::updateOrCreate(
            ['code' => 'PM-CGP-BANK-BRAC'],
            [
                'location_id' => $branchCtg->id,
                'name' => 'BRAC Bank — Chattogram',
                'type' => 'bank',
                'account_name' => 'Inventory Demo Ltd. (CTG)',
                'account_number' => '9876543210987',
                'bank_name' => 'BRAC Bank PLC',
                'bank_branch' => 'Agrabad Branch',
                'routing_number' => '060260131',
                'swift_code' => 'BRAKBDDH',
                'is_active' => true,
                'notes' => 'Local branch bank deposits.',
                'created_by' => null,
            ]
        );
    }
}
