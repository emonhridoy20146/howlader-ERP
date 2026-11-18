<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 40)->default('general');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('sms_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_token')->unique();
            $table->string('kind', 20)->default('bulk'); // bulk | single
            $table->string('audience', 40)->nullable();
            $table->json('filters')->nullable();
            $table->text('message_template');
            $table->string('sender_type', 20);
            $table->string('sender_id', 40)->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('total_parts')->default(0);
            $table->string('status', 20)->default('queued'); // queued | processing | completed | failed
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_batch_id')->nullable()->constrained('sms_batches')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('phone', 20);
            $table->text('message');
            $table->string('sender_type', 20);
            $table->string('sender_id', 40)->nullable();
            $table->boolean('is_unicode')->default(false);
            $table->unsignedSmallInteger('characters')->default(0);
            $table->unsignedSmallInteger('parts')->default(1);
            $table->string('status', 20)->default('pending'); // pending | sent | failed
            $table->string('response_code', 20)->nullable();
            $table->string('response_message')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('audience', 40)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('phone');
        });

        $now = now();
        DB::table('sms_templates')->insert([
            [
                'name' => 'কিস্তির অগ্রিম রিমাইন্ডার',
                'category' => 'installment_upcoming',
                'body' => "প্রিয় {name}, আপনার ইনভয়েস {invoice_no} এর কিস্তি {due_amount} টাকা {due_date} তারিখে পরিশোধযোগ্য। অনুগ্রহ করে সময়মতো পরিশোধ করুন। - {company}",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'আজ কিস্তির শেষ দিন',
                'category' => 'installment_due_today',
                'body' => "প্রিয় {name}, আজ আপনার কিস্তি {due_amount} টাকা পরিশোধের শেষ দিন। ধন্যবাদ। - {company}",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'বকেয়া কিস্তি (ওভারডিউ)',
                'category' => 'installment_overdue',
                'body' => "প্রিয় {name}, আপনার {due_amount} টাকার কিস্তি {due_date} থেকে {overdue_days} দিন বকেয়া আছে। দ্রুত পরিশোধ করার অনুরোধ রইলো। - {company}",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'বাকি বিক্রয়ের রিমাইন্ডার',
                'category' => 'credit_upcoming',
                'body' => "প্রিয় {name}, ইনভয়েস {invoice_no} এর বাকি {due_amount} টাকা {due_date} তারিখের মধ্যে পরিশোধ করুন। - {company}",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'বাকি টাকা মেয়াদোত্তীর্ণ',
                'category' => 'credit_overdue',
                'body' => "প্রিয় {name}, আপনার বাকি {due_amount} টাকার পরিশোধের তারিখ {due_date} পার হয়েছে। অনুগ্রহ করে দ্রুত পরিশোধ করুন। - {company}",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'নতুন ক্রয়ের ধন্যবাদ',
                'category' => 'new_sales',
                'body' => "প্রিয় {name}, {company} থেকে কেনাকাটার জন্য ধন্যবাদ। ইনভয়েস {invoice_no}, মোট {sale_amount} টাকা।",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'পেমেন্ট প্রাপ্তি',
                'category' => 'payment_received',
                'body' => "প্রিয় {name}, আপনার {paid_amount} টাকা পেমেন্ট গ্রহণ করা হয়েছে। মোট বকেয়া {total_due} টাকা। ধন্যবাদ। - {company}",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'মোট বকেয়া রিমাইন্ডার',
                'category' => 'outstanding_dues',
                'body' => "প্রিয় {name}, আপনার মোট বকেয়া {total_due} টাকা। অনুগ্রহ করে শীঘ্রই পরিশোধ করুন। - {company}",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'অফার / ঘোষণা',
                'category' => 'general',
                'body' => "প্রিয় {name}, {company} এ চলছে বিশেষ অফার! আজই আমাদের শোরুমে আসুন।",
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_batches');
        Schema::dropIfExists('sms_templates');
    }
};
