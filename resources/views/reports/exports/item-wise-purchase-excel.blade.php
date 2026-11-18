<table>
    <thead>
        <tr>
            <th colspan="5">{{ $companyPad['company_name'] ?? 'Company Name' }}</th>
        </tr>
        <tr>
            <th colspan="5">{{ $reportTitle }} ({{ $subtitle }})</th>
        </tr>
        <tr></tr>
        <tr>
            <th>SKU</th>
            <th>Product Name</th>
            <th>Total Qty Ordered</th>
            <th>Total Qty Received</th>
            <th>Total Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            <tr>
                <td>{{ $row->sku }}</td>
                <td>{{ $row->name }}</td>
                <td>{{ $row->total_qty_ordered }}</td>
                <td>{{ $row->total_qty_received }}</td>
                <td>{{ $row->total_amount }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
