<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>INVOICE {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 28px 32px 36px; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
        }
        .header {
            width: 100%;
            margin-bottom: 8px;
        }
        .header td { vertical-align: top; }
        .brand {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 0.5px;
            line-height: 1.1;
        }
        .brand .albedo { color: #1e5bb8; }
        .brand .educator { color: #6b3fa0; font-size: 13px; display: block; margin-top: 2px; letter-spacing: 2px; }
        .contact {
            text-align: right;
            font-size: 9.5px;
            line-height: 1.45;
            color: #222;
        }
        .contact .addr { margin-top: 4px; max-width: 280px; margin-left: auto; }
        .rule {
            border: none;
            border-top: 2px solid #5c2d91;
            margin: 10px 0 14px;
        }
        .meta {
            width: 100%;
            margin-bottom: 10px;
        }
        .meta td { vertical-align: middle; }
        .si-no { font-size: 12px; font-weight: bold; }
        .title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            text-decoration: underline;
            letter-spacing: 1px;
        }
        table.info, table.fees, table.pkg {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.info td, table.fees th, table.fees td, table.pkg th, table.pkg td {
            border: 1px solid #222;
            padding: 6px 8px;
        }
        table.info td.label {
            font-weight: bold;
            width: 70px;
            background: #fafafa;
        }
        table.info td.date-label { width: 55px; font-weight: bold; background: #fafafa; }
        table.fees th, table.pkg th {
            background: #f3f3f3;
            font-size: 10px;
            text-align: center;
            font-weight: bold;
        }
        table.fees td.num, table.pkg td.num, table.fees th.num {
            text-align: center;
            width: 60px;
        }
        table.fees td.amt, table.pkg td.amt {
            text-align: right;
            width: 110px;
        }
        table.fees td.total-label, table.pkg td.total-label {
            font-weight: bold;
            text-align: right;
        }
        table.fees tr.total td, table.pkg tr.total td {
            font-weight: bold;
        }
        .section-title {
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            margin: 4px 0 8px;
            letter-spacing: 0.5px;
        }
        .payment {
            color: #1e4f9a;
            font-style: italic;
            font-size: 11px;
            line-height: 1.55;
            margin: 8px 0 20px;
        }
        .payment strong { font-style: normal; }
        .bottom-bar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            height: 10px;
            background: linear-gradient(to right, #1e5bb8 0%, #1e5bb8 55%, #6b3fa0 55%, #6b3fa0 100%);
        }
        .hours { text-transform: uppercase; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 42%;">
                @if($logoPath)
                    <img src="{{ $logoPath }}" alt="albedo THE EDUCATOR" style="max-height: 120px; width: auto;">
                @else
                    <div class="brand">
                        <span class="albedo">albedo</span>
                        <span class="educator">THE EDUCATOR</span>
                    </div>
                @endif
            </td>
            <td class="contact">
                {{ $company['phone'] ?? '' }}<br>
                {{ $company['email'] ?? '' }}<br>
                {{ $company['website'] ?? '' }} &nbsp; {{ $company['social'] ?? '' }}<br>
                <div class="addr">{{ $company['address'] ?? '' }}</div>
            </td>
        </tr>
    </table>

    <hr class="rule">

    <table class="meta">
        <tr>
            <td class="si-no" style="width: 40%;">SI.NO: {{ $invoice->invoice_number }}</td>
            <td class="title">INVOICE</td>
            <td style="width: 40%;"></td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td class="label">NAME</td>
            <td>{{ strtoupper($invoice->student_name) }}</td>
            <td class="date-label">DATE</td>
            <td style="width: 110px;">{{ $invoice->invoice_date?->format('d-m-Y') }}</td>
        </tr>
        <tr>
            <td class="label">CLASS</td>
            <td colspan="3">{{ $invoice->class_label }}</td>
        </tr>
    </table>

    <table class="fees">
        <thead>
            <tr>
                <th class="num">SI NO</th>
                <th>PARTICULARS</th>
                <th>AMOUNT (INR)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="num">01</td>
                <td>TUITION FEE</td>
                <td class="amt">{{ number_format((float) $invoice->tuition_fee, 0, '.', '') }}</td>
            </tr>
            <tr>
                <td class="num">02</td>
                <td>ADMISSION FEE</td>
                <td class="amt">{{ number_format((float) $invoice->admission_fee, 0, '.', '') }}</td>
            </tr>
            <tr class="total">
                <td colspan="2" class="total-label">TOTAL FEE</td>
                <td class="amt">{{ number_format((float) $invoice->total_fee, 0, '.', '') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">PACKAGE DETAILS</div>
    <table class="pkg">
        <thead>
            <tr>
                <th>SESSIONS</th>
                <th>HOURS (PER SESSIONS)</th>
                <th>AMOUNT (PER SESSION)</th>
                <th>AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="num">{{ $invoice->sessions }}</td>
                <td class="num hours">{{ $invoice->hours_per_session }}{{ str_contains(strtoupper((string) $invoice->hours_per_session), 'HR') ? '' : ' HR' }}</td>
                <td class="amt">{{ number_format((float) $invoice->amount_per_session, 0, '.', '') }}</td>
                <td class="amt">{{ number_format((float) $packageAmount, 0, '.', '') }}</td>
            </tr>
            <tr>
                <td colspan="3" class="total-label">STUDY MATERIALS</td>
                <td class="amt">{{ number_format((float) $invoice->study_materials, 0, '.', '') }}</td>
            </tr>
            <tr class="total">
                <td colspan="3" class="total-label">TOTAL</td>
                <td class="amt">{{ number_format((float) $packageTotal, 0, '.', '') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title" style="text-align: left; margin-bottom: 4px;">PAYMENT DETAILS</div>
    <div class="payment">
        Google Pay Number : {{ $payment['gpay_number'] ?? '' }}<br>
        Google Pay Id : {{ $payment['gpay_id'] ?? '' }}<br>
        Bank Account Details :<br>
        A/c No : {{ $payment['account_number'] ?? '' }}<br>
        Name : {{ $payment['account_name'] ?? '' }}<br>
        IFSC : {{ $payment['ifsc'] ?? '' }}<br>
        Branch : {{ $payment['branch'] ?? '' }}
    </div>

    <div class="bottom-bar"></div>
</body>
</html>
