<script>
(function () {
    const wallets = JSON.parse(document.getElementById('wallets-json').textContent);
    const branchSelect = document.getElementById('journal-shared-branch');
    const prefillWalletId = @json($prefillWalletId);
    let expensePrefillApplied = false;
    let incomePrefillApplied = false;
    let transferPrefillApplied = false;

    function walletAllowed(wallet, role) {
        if (role === 'expense-from') {
            return wallet.allow_expense_from !== false;
        }
        if (role === 'income-to') {
            return true;
        }
        if (role === 'opening') {
            return wallet.allow_opening_balance !== false;
        }
        if (role === 'transfer-from') {
            return wallet.allow_transfer_from !== false;
        }

        return true;
    }

    function fillSelect(select, branchId, options) {
        if (!select) {
            return;
        }

        const role = select.dataset.walletRole || '';
        const excludeId = options && options.excludeId ? String(options.excludeId) : '';
        const previous = select.value;

        select.innerHTML = '';

        (wallets[String(branchId)] || []).forEach(function (wallet) {
            if (excludeId !== '' && String(wallet.id) === excludeId) {
                return;
            }
            if (!walletAllowed(wallet, role)) {
                return;
            }
            select.appendChild(new Option(wallet.label, wallet.id));
        });

        if (previous && [...select.options].some(function (option) { return option.value === previous; })) {
            select.value = previous;
        } else if (select.options.length > 0 && select.value === '') {
            select.selectedIndex = 0;
        }
    }

    function syncWalletSelects() {
        const branchId = branchSelect.value;
        const trFrom = document.getElementById('tr-from');
        const trTo = document.getElementById('tr-to');

        document.querySelectorAll('.journal-wallet-select').forEach(function (select) {
            if (select.id === 'tr-to') {
                return;
            }
            fillSelect(select, branchId);
        });

        fillSelect(trTo, branchId, { excludeId: trFrom ? trFrom.value : '' });

        const expWallet = document.getElementById('exp-wallet');
        if (expWallet && !expensePrefillApplied && prefillWalletId && [...expWallet.options].some(function (o) { return o.value === String(prefillWalletId); })) {
            expWallet.value = String(prefillWalletId);
            expensePrefillApplied = true;
        }

        const incWallet = document.getElementById('inc-wallet');
        if (incWallet && !incomePrefillApplied && prefillWalletId && [...incWallet.options].some(function (o) { return o.value === String(prefillWalletId); })) {
            incWallet.value = String(prefillWalletId);
            incomePrefillApplied = true;
        }

        if (trFrom && !transferPrefillApplied && prefillWalletId && [...trFrom.options].some(function (o) { return o.value === String(prefillWalletId); })) {
            trFrom.value = String(prefillWalletId);
            transferPrefillApplied = true;
            fillSelect(trTo, branchId, { excludeId: trFrom.value });
        }

        const obWallet = document.getElementById('ob-wallet');
        if (obWallet) {
            const preferred = obWallet.getAttribute('data-initial-wallet') || '';
            if (preferred && [...obWallet.options].some(function (o) { return o.value === String(preferred); })) {
                obWallet.value = String(preferred);
            }
            obWallet.removeAttribute('data-initial-wallet');
        }
    }

    branchSelect.addEventListener('change', syncWalletSelects);

    const trFrom = document.getElementById('tr-from');
    if (trFrom) {
        trFrom.addEventListener('change', syncWalletSelects);
    }

    syncWalletSelects();
})();
</script>
