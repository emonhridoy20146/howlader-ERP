<table>
    <thead>
        <tr>
            <th colspan="4">{{ $companyPad['company_name'] ?? 'Company Name' }}</th>
        </tr>
        <tr>
            <th colspan="4">{{ $reportTitle }} ({{ $subtitle }})</th>
        </tr>
        <tr></tr>
        <tr>
            <th>SKU</th>
            <th>Product Name</th>
            <th>Total Quantity</th>
            <th>Total Revenue</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            <tr>
                <td>{{ $row->sku }}</td>
                <td>{{ $row->name }}</td>
                <td>{{ $row->total_qty }}</td>
                <td>{{ $row->total_revenue }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
