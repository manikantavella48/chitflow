<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Chit Calculator';
$currentPage = 'calculator';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/app-nav.php';
?>

<div class="page-header">
    <h1>Chit Calculator</h1>
    <p>Simulate auctions, calculate payouts, dividends, and model returns.</p>
</div>

<div class="calc-presets">
    <button class="btn btn-outline btn-sm" onclick="applyPreset(100000,20)">₹1L - 20 Months</button>
    <button class="btn btn-outline btn-sm" onclick="applyPreset(250000,25)">₹2.5L - 25 Months</button>
    <button class="btn btn-outline btn-sm" onclick="applyPreset(500000,50)">₹5L - 50 Months</button>
    <button class="btn btn-outline btn-sm" onclick="applyPreset(1000000,50)">₹10L - 50 Months</button>
</div>

<div class="card" style="margin-bottom:24px;">
    <h3 class="card-title">Configure Parameters</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:20px;">
        <div class="calc-input-group">
            <div class="calc-input-header">
                <label>Chit Amount (₹)</label>
                <input type="number" id="chitValue" class="calc-value-input" value="500000" onchange="calculate()">
            </div>
            <input type="range" id="chitSlider" class="calc-slider" min="1000" max="2000000" step="1000" value="500000" oninput="document.getElementById('chitValue').value=this.value;calculate()">
        </div>
        <div class="calc-input-group">
            <div class="calc-input-header">
                <label>Tenure (Months)</label>
                <input type="number" id="tenure" class="calc-value-input" value="50" onchange="calculate()">
            </div>
            <input type="range" id="tenureSlider" class="calc-slider" min="2" max="100" step="1" value="50" oninput="document.getElementById('tenure').value=this.value;calculate()">
        </div>
        <div class="calc-input-group">
            <div class="calc-input-header">
                <label>Commission (%)</label>
                <input type="number" id="commission" class="calc-value-input" value="5" step="0.5" onchange="calculate()">
            </div>
            <input type="range" id="commissionSlider" class="calc-slider" min="0" max="20" step="0.5" value="5" oninput="document.getElementById('commission').value=this.value;calculate()">
        </div>
        <div class="calc-input-group">
            <div class="calc-input-header">
                <label>Bid Discount (%)</label>
                <input type="number" id="bidDiscount" class="calc-value-input" value="30" step="0.5" onchange="calculate()">
            </div>
            <input type="range" id="bidDiscountSlider" class="calc-slider" min="5" max="50" step="0.5" value="30" oninput="document.getElementById('bidDiscount').value=this.value;calculate()">
        </div>
    </div>
    <div style="margin-top:16px;padding:16px;background:var(--bg);border-radius:var(--radius);">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);">Base Monthly Installment</div>
        <div style="font-family:var(--font-display);font-size:24px;font-weight:700;margin-top:4px;" id="baseInstallment">₹10,000</div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Auction Results</h3>
    <div class="calc-bar" id="calcBar"></div>
    <div class="calc-results">
        <div class="calc-result-card calc-result-winner">
            <div class="calc-result-label" style="color:var(--primary);">Winner Receives</div>
            <div class="calc-result-amount" style="color:var(--primary);" id="winnerPayout">₹3,50,000</div>
        </div>
        <div class="calc-result-card calc-result-member">
            <div class="calc-result-label" style="color:var(--success);">Net Installment</div>
            <div class="calc-result-amount" style="color:var(--success);" id="netInstallment">₹8,500</div>
            <div style="font-size:12px;color:var(--success);margin-top:4px;" id="dividendSaved">Saved ₹1,500 this month</div>
        </div>
    </div>
</div>

</main>
<script src="<?= APP_URL ?>/assets/js/calculator.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
