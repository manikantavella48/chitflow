function formatRupees(val) {
    return '₹' + Math.round(val).toLocaleString('en-IN');
}

function applyPreset(amount, months) {
    document.getElementById('chitValue').value = amount;
    document.getElementById('chitSlider').value = amount;
    document.getElementById('tenure').value = months;
    document.getElementById('tenureSlider').value = months;
    document.getElementById('bidDiscount').value = months >= 50 ? 35 : 25;
    document.getElementById('bidDiscountSlider').value = months >= 50 ? 35 : 25;
    calculate();
}

function calculate() {
    const chitValue = parseFloat(document.getElementById('chitValue').value) || 0;
    const tenure = parseInt(document.getElementById('tenure').value) || 1;
    const commission = parseFloat(document.getElementById('commission').value) || 0;
    const bidDiscountPct = parseFloat(document.getElementById('bidDiscount').value) || 0;

    const originalInstallment = chitValue / tenure;
    const foremanCommission = (chitValue * commission) / 100;
    const bidDiscountVal = (chitValue * bidDiscountPct) / 100;
    const totalDividend = Math.max(0, bidDiscountVal - foremanCommission);
    const dividendPerMember = totalDividend / tenure;
    const prizeMoney = chitValue - bidDiscountVal;
    const netInstallment = originalInstallment - dividendPerMember;

    document.getElementById('baseInstallment').textContent = formatRupees(originalInstallment);
    document.getElementById('winnerPayout').textContent = formatRupees(prizeMoney);
    document.getElementById('netInstallment').textContent = formatRupees(netInstallment);
    document.getElementById('dividendSaved').textContent = 'Saved ' + formatRupees(dividendPerMember) + ' this month';

    const winnerPct = 100 - bidDiscountPct;
    const feePct = commission;
    const divPct = Math.max(0, bidDiscountPct - commission);

    document.getElementById('calcBar').innerHTML = `
        <div class="calc-bar-winner" style="width:${winnerPct}%">Winner ${winnerPct.toFixed(0)}%</div>
        <div class="calc-bar-fee" style="width:${feePct}%">Fee ${feePct}%</div>
        ${divPct > 0 ? `<div class="calc-bar-dividend" style="width:${divPct}%">Dividends ${divPct.toFixed(0)}%</div>` : ''}
    `;
}

document.addEventListener('DOMContentLoaded', calculate);
