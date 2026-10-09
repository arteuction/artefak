<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  body        { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 0; }
  .header     { background: #0d1117; color: #fff; padding: 24px 32px 20px; }
  .header h1  { font-size: 18px; margin: 0 0 4px; }
  .header p   { margin: 0; font-size: 10px; color: #9ca3af; }
  .body       { padding: 28px 32px; }
  .section    { margin-bottom: 20px; }
  h2          { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; margin: 0 0 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
  table       { width: 100%; border-collapse: collapse; }
  td, th      { padding: 5px 8px; text-align: left; }
  th          { background: #f3f4f6; font-weight: bold; }
  tr.total td { border-top: 2px solid #0d1117; font-weight: bold; }
  .badge      { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: bold; }
  .badge-ok   { background: #d1fae5; color: #065f46; }
  .badge-warn { background: #fef9c3; color: #713f12; }
  .footer     { margin-top: 32px; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
</style>
</head>
<body>

<div class="header">
  <h1>ARTeuCtion — Settlement Statement</h1>
  <p>Reference: {{ $settlement->stripe_payment_intent_id }}
     &nbsp;·&nbsp; Generated: {{ now()->format('d M Y H:i') }} UTC</p>
</div>

<div class="body">

  <div class="section">
    <h2>Settlement Summary</h2>
    <table>
      <tr><th>Field</th><th>Value</th></tr>
      <tr><td>Payment Intent</td><td>{{ $settlement->stripe_payment_intent_id }}</td></tr>
      <tr><td>Currency</td><td>EUR</td></tr>
      <tr><td>Gross Amount</td><td>€{{ number_format($settlement->gross_cents / 100, 2) }}</td></tr>
      <tr><td>Split Profile</td><td>{{ $settlement->profile_key }} (v{{ $settlement->profile_version }})</td></tr>
      <tr><td>Status</td>
          <td><span class="badge {{ $settlement->status === 'completed' ? 'badge-ok' : 'badge-warn' }}">
              {{ strtoupper($settlement->status) }}</span></td></tr>
      <tr><td>Created</td><td>{{ \Carbon\Carbon::parse($settlement->created_at)->format('d M Y H:i') }} UTC</td></tr>
    </table>
  </div>

  <div class="section">
    <h2>Revenue Split</h2>
    <table>
      <tr><th>Recipient</th><th>Basis Points</th><th>Amount (EUR)</th></tr>
      <tr>
        <td>Artist</td>
        <td>{{ $settlement->artist_bps }} bps ({{ number_format($settlement->artist_bps / 100, 1) }}%)</td>
        <td>€{{ number_format($settlement->artist_cents / 100, 2) }}</td>
      </tr>
      <tr>
        <td>Fund / Institution</td>
        <td>{{ $settlement->fund_bps }} bps ({{ number_format($settlement->fund_bps / 100, 1) }}%)</td>
        <td>€{{ number_format($settlement->fund_cents / 100, 2) }}</td>
      </tr>
      <tr>
        <td>Operations</td>
        <td>{{ $settlement->ops_bps }} bps ({{ number_format($settlement->ops_bps / 100, 1) }}%)</td>
        <td>€{{ number_format($settlement->ops_cents / 100, 2) }}</td>
      </tr>
      <tr class="total">
        <td colspan="2">Total</td>
        <td>€{{ number_format($settlement->gross_cents / 100, 2) }}</td>
      </tr>
    </table>
  </div>

  @if($lines->isNotEmpty())
  <div class="section">
    <h2>Settlement Lines</h2>
    <table>
      <tr><th>#</th><th>Recipient</th><th>Type</th><th>Amount</th><th>Status</th></tr>
      @foreach($lines as $i => $line)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $line->recipient_type ?? '—' }}</td>
        <td>{{ $line->line_type ?? '—' }}</td>
        <td>€{{ number_format($line->amount_cents / 100, 2) }}</td>
        <td>{{ $line->status ?? '—' }}</td>
      </tr>
      @endforeach
    </table>
  </div>
  @endif

  <div class="footer">
    <p>This document is generated from ARTeuCtion's append-only settlement ledger.
       All figures are in Euro (EUR). Bulgaria adopted the Euro on 2026-01-01.
       This statement is for informational purposes only and does not constitute
       a tax invoice. Retain for accounting purposes.</p>
    <p>ARTeuCtion · arteuction.bg</p>
  </div>

</div>
</body>
</html>
