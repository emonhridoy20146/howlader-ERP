<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 8)->nullable()->after('address');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->text('detailed_address')->nullable()->after('longitude');
            $table->text('location_instructions')->nullable()->after('detailed_address');
            $table->string('house_image_path', 511)->nullable()->after('location_instructions');
            $table->string('location_image_path', 511)->nullable()->after('house_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'latitude',
                'longitude',
                'detailed_address',
                'location_instructions',
                'house_image_path',
                'location_image_path',
            ]);
        });
    }
};
