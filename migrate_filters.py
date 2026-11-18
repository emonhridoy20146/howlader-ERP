import os
import re

base_dir = '/Users/sohojware/Dev/laravel/inventorynew/resources/views'

files_to_process = [
    "reports/open-purchase-orders.blade.php",
    "reports/item-wise-sales.blade.php",
    "reports/customer-dues.blade.php",
    "reports/month-to-date-sales.blade.php",
    "reports/item-wise-purchase.blade.php",
    "reports/sales-summary.blade.php",
    "reports/low-stock.blade.php",
    "reports/stock-by-location.blade.php",
    "reports/representative-wise-sales.blade.php",
    "returns/index.blade.php",
    "wallets/index.blade.php",
    "goods-receipts/index.blade.php",
    "categories/index.blade.php",
    "locations/index.blade.php",
    "payment-methods/index.blade.php",
    "payment-method-wallet-maps/index.blade.php",
    "installments/index.blade.php",
    "transfers/index.blade.php",
    "brands/index.blade.php",
    "purchase-orders/index.blade.php",
    "suppliers/index.blade.php",
    "journal-entries/index.blade.php",
    "sales/invoices/index.blade.php",
    "ledger-accounts/index.blade.php",
    "products/index.blade.php",
    "service-jobs/index.blade.php",
    "customers/index.blade.php"
]

for filename in files_to_process:
    path = os.path.join(base_dir, filename)
    if not os.path.exists(path):
        print(f"Skipping {filename}, not found.")
        continue
        
    with open(path, 'r') as f:
        content = f.read()

    # Find the filter form: <form method="get" ...
    match = re.search(r'(<form\s+method="get"[^>]*>)(.*?)(</form>)', content, re.DOTALL | re.IGNORECASE)
    if not match:
        print(f"No filter form found in {filename}")
        continue
        
    form_tag = match.group(1)
    form_body = match.group(2)
    form_close = match.group(3)
    
    # Rewrite form tag to remove styling classes that don't belong in a drawer
    action_match = re.search(r'action="([^"]*)"', form_tag)
    action = action_match.group(1) if action_match else ""
    new_form_tag = f'<form method="get" action="{action}" class="flex flex-col gap-4">'
    
    # Strip responsive grid classes from form_body
    # e.g., md:grid-cols-12, sm:grid-cols-6, lg:grid-cols-..., xl:grid-cols-...
    form_body = re.sub(r'\b(sm|md|lg|xl|2xl):grid-cols-\d+\b', 'flex flex-col gap-4', form_body)
    form_body = re.sub(r'\bgrid-cols-\d+\b', 'flex flex-col gap-4', form_body)
    form_body = re.sub(r'\b(sm|md|lg|xl|2xl):col-span-\d+\b', '', form_body)
    form_body = re.sub(r'\bcol-span-\d+\b', '', form_body)
    # clean up any leftover 'grid' or 'flex' classes that might conflict, or just replace '<div class="grid ' with '<div class="flex flex-col gap-4 '
    form_body = re.sub(r'class="[^"]*\bgrid\b[^"]*"', 'class="flex flex-col gap-4"', form_body)
    
    # Wrap in drawer
    new_block = f'<x-filter-drawer>\n{new_form_tag}{form_body}{form_close}\n</x-filter-drawer>'
    
    new_content = content[:match.start()] + new_block + content[match.end():]
    
    with open(path, 'w') as f:
        f.write(new_content)
        
    print(f"Updated {filename}")

