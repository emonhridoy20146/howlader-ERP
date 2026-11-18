<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BangladeshInventorySeeder extends Seeder
{
    public function run(): void
    {
        $brands = $this->seedBrands();
        $categories = $this->seedCategories();
        $this->seedSuppliers();
        $this->seedCustomers();
        $this->seedProducts($brands, $categories);
    }

    /**
     * @return array<string, Brand>
     */
    private function seedBrands(): array
    {
        $definitions = [
            'Walton' => 'Bangladesh',
            'Marcel' => 'Bangladesh',
            'Vision' => 'Bangladesh',
            'Samsung' => 'South Korea',
            'Xiaomi' => 'China',
            'LG' => 'South Korea',
            'Singer' => 'Bangladesh',
            'Philips' => 'Netherlands',
            'Dell' => 'United States',
            'HP' => 'United States',
        ];

        $byKey = [];
        foreach ($definitions as $name => $country) {
            $brand = Brand::updateOrCreate(
                ['name' => $name],
                ['country_of_origin' => $country]
            );
            $byKey[Str::slug($name, '_')] = $brand;
        }

        return $byKey;
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $parents = [
            ['key' => 'mobile_electronics', 'name' => 'মোবাইল ও ইলেকট্রনিক্স', 'sort' => 10],
            ['key' => 'home_appliances', 'name' => 'হোম অ্যাপ্লায়েন্স', 'sort' => 20],
            ['key' => 'computing', 'name' => 'কম্পিউটার ও আনুষংগিক', 'sort' => 30],
            ['key' => 'grocery', 'name' => 'গ্রোসারি ও দৈনন্দিন', 'sort' => 40],
        ];

        $byKey = [];
        foreach ($parents as $row) {
            $byKey[$row['key']] = Category::firstOrCreate(
                ['name' => $row['name'], 'parent_id' => null],
                ['sort_order' => $row['sort']]
            );
        }

        $children = [
            ['parent' => 'mobile_electronics', 'key' => 'smartphones', 'name' => 'স্মার্টফোন', 'sort' => 11],
            ['parent' => 'mobile_electronics', 'key' => 'tablets', 'name' => 'ট্যাবলেট ও নেটবুক', 'sort' => 12],
            ['parent' => 'home_appliances', 'key' => 'refrigerators', 'name' => 'রেফ্রিজারেটর ও ফ্রিজ', 'sort' => 21],
            ['parent' => 'home_appliances', 'key' => 'tv_audio', 'name' => 'টিভি ও অডিও', 'sort' => 22],
            ['parent' => 'computing', 'key' => 'laptops', 'name' => 'ল্যাপটপ ও ডেস্কটপ', 'sort' => 31],
            ['parent' => 'computing', 'key' => 'accessories', 'name' => 'অ্যাক্সেসরিজ ও পেরিফেরাল', 'sort' => 32],
            ['parent' => 'grocery', 'key' => 'staples', 'name' => 'নিত্যপ্রয়োজনীয় খাদ্য', 'sort' => 41],
        ];

        foreach ($children as $row) {
            $parent = $byKey[$row['parent']];
            $byKey[$row['key']] = Category::firstOrCreate(
                ['name' => $row['name'], 'parent_id' => $parent->id],
                ['sort_order' => $row['sort']]
            );
        }

        return $byKey;
    }

    private function seedSuppliers(): void
    {
        $rows = [
            [
                'name' => 'ঢাকা ট্রেডিং কর্পোরেশন',
                'contact_person' => 'মোঃ করিম উদ্দিন',
                'phone' => '01711223344',
                'email' => 'sales@dhakatraders.bd',
                'address' => 'মিরপুর-১০, ঢাকা-১২১৬',
                'tax_number' => '123456789012',
            ],
            [
                'name' => 'চট্টগ্রাম হোলসেল হাব',
                'contact_person' => 'ফাতেমা বেগম',
                'phone' => '01822334455',
                'email' => 'procurement@ctgwholesale.com',
                'address' => 'আগ্রাবাদ বাণিজ্যিক এলাকা, চট্টগ্রাম-৪১০০',
                'tax_number' => '987654321098',
            ],
            [
                'name' => 'বসুন্ধরা ইমপোর্ট অ্যান্ড সাপ্লাই',
                'contact_person' => 'রাফি হাসান',
                'phone' => '01933445566',
                'email' => 'supply@bashundhara-import.bd',
                'address' => 'বসুন্ধরা সিটি, পান্থপথ, ঢাকা-১২১৯',
                'tax_number' => '112233445566',
            ],
            [
                'name' => 'নিউ মার্কেট ইলেকট্রনিক্স',
                'contact_person' => 'সেলিম রেজা',
                'phone' => '01644556677',
                'email' => 'orders@nm-electronics.bd',
                'address' => 'নিউ মার্কেট, ঢাকা-১০০০',
                'tax_number' => '556677889900',
            ],
            [
                'name' => 'খুলনা ফ্রেশ ফুডস লিমিটেড',
                'contact_person' => 'নুসরাত জাহান',
                'phone' => '01555667788',
                'email' => 'b2b@khulnafreshfoods.com',
                'address' => 'শিববাড়ি মোড়, খুলনা-৯১০০',
                'tax_number' => '998877665544',
            ],
        ];

        foreach ($rows as $data) {
            Supplier::updateOrCreate(
                ['name' => $data['name']],
                array_merge($data, [
                    'outstanding_balance' => 0,
                    'is_active' => true,
                ])
            );
        }
    }

    private function seedCustomers(): void
    {
        $rows = [
            [
                'customer_code' => 'BD-CUST-0001',
                'name' => 'মোঃ আরিফ হোসেন',
                'phone' => '01711112222',
                'email' => 'arif.hossain@example.bd',
                'address' => 'বাড়ি ১২, রোড ৫, ধানমন্ডি, ঢাকা-১২০৯',
                'id_type' => 'NID',
                'id_number' => '1234567890',
                'credit_limit' => 150000.00,
            ],
            [
                'customer_code' => 'BD-CUST-0002',
                'name' => 'সাবরিনা আক্তার',
                'phone' => '01822223333',
                'email' => 'sabrina.akther@example.bd',
                'address' => 'হাউজ ৪৫, ব্লক-সি, বনানী, ঢাকা-১২১৩',
                'id_type' => 'NID',
                'id_number' => '19876543210987654',
                'credit_limit' => 250000.00,
            ],
            [
                'customer_code' => 'BD-CUST-0003',
                'name' => 'কামরুল ইসলাম',
                'phone' => '01933334444',
                'email' => null,
                'address' => 'উত্তরা সেক্টর ৭, ঢাকা-১২৩০',
                'id_type' => 'NID',
                'id_number' => '9876543210',
                'credit_limit' => 50000.00,
            ],
            [
                'customer_code' => 'BD-CUST-0004',
                'name' => 'রুমানা শারমিন',
                'phone' => '01644445555',
                'email' => 'rumana.sharmin@example.bd',
                'address' => 'জিইসি মোড়, চট্টগ্রাম-৪২২৫',
                'id_type' => 'Passport',
                'id_number' => 'A12345678',
                'credit_limit' => null,
            ],
            [
                'customer_code' => 'BD-CUST-0005',
                'name' => 'তানভীর আহমেদ',
                'phone' => '01555556666',
                'email' => 'tanvir.ahmed@example.bd',
                'address' => 'শ্যামলী, মিরপুর রোড, ঢাকা-১২০৭',
                'id_type' => 'NID',
                'id_number' => '4567890123',
                'credit_limit' => 75000.00,
            ],
            [
                'customer_code' => 'BD-CUST-0006',
                'name' => 'জাহিদ হাসান',
                'phone' => '01366667777',
                'email' => null,
                'address' => 'কুয়াকাটা সৈকত রোড, পটুয়াখালী-৮৬২০',
                'id_type' => 'NID',
                'id_number' => '3210987654',
                'credit_limit' => 30000.00,
            ],
            [
                'customer_code' => 'BD-CUST-0007',
                'name' => 'নাজমা সুলতানা',
                'phone' => '01477778888',
                'email' => 'nazma.sultana@example.bd',
                'address' => 'লালবাগ, ঢাকা-১২১১',
                'id_type' => 'NID',
                'id_number' => '56789012345678901',
                'credit_limit' => 120000.00,
            ],
            [
                'customer_code' => 'BD-CUST-0008',
                'name' => 'রিয়াদ মাহমুদ',
                'phone' => '01788889999',
                'email' => 'riad.mahmud@example.bd',
                'address' => 'সিলেট কেন্দ্রীয় বাস টার্মিনাল সংলগ্ন, সিলেট-৩১০০',
                'id_type' => 'NID',
                'id_number' => '6789012345',
                'credit_limit' => 60000.00,
            ],
        ];

        foreach ($rows as $data) {
            Customer::updateOrCreate(
                ['customer_code' => $data['customer_code']],
                array_merge($data, [
                    'outstanding_balance' => 0,
                    'is_active' => true,
                ])
            );
        }
    }

    /**
     * @param  array<string, Brand>  $brands
     * @param  array<string, Category>  $categories
     */
    private function seedProducts(array $brands, array $categories): void
    {
        $definitions = [
            [
                'sku' => 'BD-WAL-RF-001',
                'barcode' => '8941234567890',
                'brand' => 'walton',
                'category' => 'refrigerators',
                'name' => 'Walton WFE-3X3 INV ফ্রস্ট রেফ্রিজারেটর',
                'model_number' => 'WFE-3X3-GD-INV',
                'description' => 'ইনভার্টার প্রযুক্তি, শক্তি সাশ্রয়ী, বাংলাদেশের জলবায়ুর জন্য উপযোগী।',
                'warranty' => 120,
                'purchase' => 28500.00,
                'sale' => 32990.00,
                'reorder' => 5,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-WAL-TV-001',
                'barcode' => '8941234567891',
                'brand' => 'walton',
                'category' => 'tv_audio',
                'name' => 'Walton 43" স্মার্ট এলইডি টিভি',
                'model_number' => 'WD43FS110',
                'description' => 'ফুল এইচডি, অ্যান্ড্রয়েড টিভি, বাংলা ইউআই সহ।',
                'warranty' => 36,
                'purchase' => 22000.00,
                'sale' => 25999.00,
                'reorder' => 8,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-MRC-FN-001',
                'barcode' => '8942234567890',
                'brand' => 'marcel',
                'category' => 'refrigerators',
                'name' => 'Marcel MAS-2X2 ডিপ ফ্রিজ',
                'model_number' => 'MAS-2X2-SS',
                'description' => 'স্টেইনলেস স্টিল ফিনিশ, দ্রুত হিমায়ন।',
                'warranty' => 60,
                'purchase' => 19800.00,
                'sale' => 23450.00,
                'reorder' => 4,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-SAM-PH-001',
                'barcode' => '8806095123456',
                'brand' => 'samsung',
                'category' => 'smartphones',
                'name' => 'Samsung Galaxy A15 (5G)',
                'model_number' => 'SM-A156E',
                'description' => '5G নেটওয়ার্ক সমর্থন, 5000 mAh ব্যাটারি।',
                'warranty' => 12,
                'purchase' => 19500.00,
                'sale' => 22499.00,
                'reorder' => 15,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-XIA-PH-001',
                'barcode' => '6934177723456',
                'brand' => 'xiaomi',
                'category' => 'smartphones',
                'name' => 'Xiaomi Redmi Note 13',
                'model_number' => '2312DRAABG',
                'description' => '108 MP ক্যামেরা, MIUI গ্লোবাল।',
                'warranty' => 12,
                'purchase' => 17800.00,
                'sale' => 20499.00,
                'reorder' => 20,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-VSN-MN-001',
                'barcode' => '8943234567890',
                'brand' => 'vision',
                'category' => 'tv_audio',
                'name' => 'Vision 32" এলইডি টিভি',
                'model_number' => 'VSN32B200',
                'description' => 'বাজেট ফ্রেন্ডলি, বাংলাদেশে জনপ্রিয় মডেল।',
                'warranty' => 36,
                'purchase' => 11200.00,
                'sale' => 13490.00,
                'reorder' => 10,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-SNG-FN-001',
                'barcode' => '8944234567890',
                'brand' => 'singer',
                'category' => 'refrigerators',
                'name' => 'Singer টপ মাউন্ট রেফ্রিজারেটর',
                'model_number' => 'SRF-210-INV',
                'description' => 'কম্প্রেসর 12 বছর ওয়ারেন্টি (শর্ত প্রযোজ্য)।',
                'warranty' => 120,
                'purchase' => 24500.00,
                'sale' => 27990.00,
                'reorder' => 6,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-DEL-LP-001',
                'barcode' => '5397184123456',
                'brand' => 'dell',
                'category' => 'laptops',
                'name' => 'Dell Inspiron 15 ল্যাপটপ',
                'model_number' => 'Inspiron 15 3530',
                'description' => 'Intel Core i5, 8 GB RAM, SSD।',
                'warranty' => 24,
                'purchase' => 52000.00,
                'sale' => 58990.00,
                'reorder' => 3,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-HP-LP-001',
                'barcode' => '0195123456789',
                'brand' => 'hp',
                'category' => 'laptops',
                'name' => 'HP 15s ল্যাপটপ',
                'model_number' => '15s-fq5000',
                'description' => 'ছাত্র ও অফিসের জন্য উপযোগী।',
                'warranty' => 24,
                'purchase' => 46500.00,
                'sale' => 52990.00,
                'reorder' => 4,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-LG-AC-001',
                'barcode' => '8806095987654',
                'brand' => 'lg',
                'category' => 'home_appliances',
                'name' => 'LG 1.5 টন ইনভার্টার এসি',
                'model_number' => 'S18EQ',
                'description' => 'দ্রুত কুলিং, শক্তি সাশ্রয়ী ইনভার্টার।',
                'warranty' => 120,
                'purchase' => 62000.00,
                'sale' => 69990.00,
                'reorder' => 2,
                'uom' => 'unit',
                'serialized' => true,
            ],
            [
                'sku' => 'BD-PHP-HD-001',
                'barcode' => '8710103790123',
                'brand' => 'philips',
                'category' => 'accessories',
                'name' => 'Philips ওভার-ইয়ার হেডফোন',
                'model_number' => 'TAH5205',
                'description' => 'Bluetooth 5.0, দীর্ঘ ব্যাটারি লাইফ।',
                'warranty' => 12,
                'purchase' => 3200.00,
                'sale' => 3990.00,
                'reorder' => 25,
                'uom' => 'unit',
                'serialized' => false,
            ],
            [
                'sku' => 'BD-WAL-KT-001',
                'barcode' => '8945234567890',
                'brand' => 'walton',
                'category' => 'staples',
                'name' => 'Walton ইলেকট্রিক কেটল 1.7 লিটার',
                'model_number' => 'WK-17L',
                'description' => 'স্টেইনলেস স্টিল বডি, অটো কাট-অফ।',
                'warranty' => 12,
                'purchase' => 950.00,
                'sale' => 1190.00,
                'reorder' => 30,
                'uom' => 'unit',
                'serialized' => false,
            ],
        ];

        foreach ($definitions as $row) {
            Product::updateOrCreate(
                ['sku' => $row['sku']],
                [
                    'barcode' => $row['barcode'],
                    'brand_id' => $brands[$row['brand']]->id,
                    'category_id' => $categories[$row['category']]->id,
                    'name' => $row['name'],
                    'model_number' => $row['model_number'],
                    'description' => $row['description'],
                    'warranty_months' => $row['warranty'],
                    'default_purchase_price' => $row['purchase'],
                    'default_sale_price' => $row['sale'],
                    'reorder_level' => $row['reorder'],
                    'unit_of_measure' => $row['uom'],
                    'is_serialized' => $row['serialized'],
                    'is_active' => true,
                ]
            );
        }
    }
}
