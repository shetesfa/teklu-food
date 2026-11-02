/**
 * ተክሉ ጌታቸው ቢዝነስ - ዋና የተጠቃሚ በይነገጽ ቁጥጥር (App Controller)
 */

// ግሎባል ተለዋዋጮች
let globalProducts = [];
let globalCategories = [];
let globalLocations = [];
let globalAccounts = [];
let globalContacts = [];
let globalCart = []; // [{product, quantity}]

// 1. መተግበሪያውን ማስነሳት (App Initialization)
window.addEventListener('DOMContentLoaded', async () => {
  API.init();
  I18N.applyTranslations();

  // ተጠቃሚ ካለ መፈተሽ
  if (!API.token) {
    // ከመስመር ውጭ የተሸጎጠ ተጠቃሚ መፈለግ
    const cached = await DB.getCachedAuth();
    if (cached && cached.token && cached.user) {
      API.token = cached.token;
      API.user = cached.user;
      initAppLayout();
    } else {
      document.getElementById('login-screen').classList.add('active');
      return;
    }
  } else {
    initAppLayout();
  }
});

function initAppLayout() {
  document.getElementById('login-screen').classList.remove('active');
  const user = API.user;
  if (user) {
    document.getElementById('app-title-el').textContent = `${I18N.t('shortName')} (${user.full_name.split(' ')[0]})`;
    // የፈቃድ ገደቦችን ማስተካከል
    if (user.role !== 'super_admin') {
      const userMgmtBtn = document.getElementById('btn-users-management');
      if (userMgmtBtn) userMgmtBtn.style.display = 'none';
    }
  }

  // የመነሻ ዳታዎችን መጫን
  loadInitialData();
  loadDashboard('today');
}

// 2. መግቢያ ማስተናገድ (Login Handler)
async function handleLogin(e) {
  e.preventDefault();
  const uInput = document.getElementById('login-username');
  const pInput = document.getElementById('login-password');
  const errBox = document.getElementById('login-error-alert');
  const submitBtn = document.getElementById('btn-login-submit');

  const u = uInput.value.trim();
  const p = pInput.value;

  if (errBox) errBox.style.display = 'none';
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.textContent = 'በመግባት ላይ... (Logging in...)';
  }

  try {
    const res = await API.login(u, p);
    if (res.success) {
      initAppLayout();
    } else {
      if (errBox) {
        errBox.textContent = res.error || 'የተሳሳተ የተጠቃሚ ስም ወይም የይለፍ ቃል';
        errBox.style.display = 'block';
      } else {
        alert(res.error || 'መግባት አልተሳካም');
      }
    }
  } catch (err) {
    if (errBox) {
      errBox.textContent = 'የኔትወርክ ወይም የሰርቨር ስህተት: ' + err.message;
      errBox.style.display = 'block';
    } else {
      alert(err.message || 'የኔትወርክ ስህተት');
    }
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = I18N.t('loginBtn');
    }
  }
}

function fillDemoCredentials(role) {
  const uInput = document.getElementById('login-username');
  const pInput = document.getElementById('login-password');
  const errBox = document.getElementById('login-error-alert');
  if (errBox) errBox.style.display = 'none';

  if (role === 'admin') {
    if (uInput) uInput.value = 'admin';
    if (pInput) pInput.value = 'Admin@123';
  } else if (role === 'manager') {
    if (uInput) uInput.value = 'manager';
    if (pInput) pInput.value = 'Manager@123';
  }
}

function togglePasswordVisibility() {
  const pInput = document.getElementById('login-password');
  if (pInput) {
    pInput.type = (pInput.type === 'password') ? 'text' : 'password';
  }
}

// 3. ዳሰሳ መቀያየሪያ (Page Navigation)
function switchPage(pageId, navButton) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.bottom-nav .nav-item').forEach(b => b.classList.remove('active'));

  const targetPage = document.getElementById(pageId);
  if (targetPage) targetPage.classList.add('active');
  if (navButton) navButton.classList.add('active');

  // ገጹ ሲከፈት መረጃዎችን ማደስ
  if (pageId === 'page-dashboard') loadDashboard('today');
  else if (pageId === 'page-pos') renderPosProducts();
  else if (pageId === 'page-stock') loadStock();
  else if (pageId === 'page-finance') { loadExpenses(); loadWithdrawals(); }
}

function showSubModule(subId) {
  document.querySelectorAll('.sub-module').forEach(sm => sm.style.display = 'none');
  const target = document.getElementById(subId);
  if (target) {
    target.style.display = 'block';
    target.scrollIntoView({ behavior: 'smooth' });

    if (subId === 'sub-transfers') loadTransfers();
    else if (subId === 'sub-farm') loadFarmProductions();
    else if (subId === 'sub-purchases') loadPurchases();
    else if (subId === 'sub-recipes') loadRecipes();
    else if (subId === 'sub-closing') loadDailyClosing();
    else if (subId === 'sub-reports') loadReport('pl');
    else if (subId === 'sub-contacts') loadContacts();
    else if (subId === 'sub-users') loadUsers();
  }
}

// 4. የመነሻ መረጃዎችን መጫን (Load Initial Data)
async function loadInitialData() {
  try {
    const res = await API.request('products.php');
    if (res.success && res.data) {
      globalProducts = res.data.products || [];
      globalCategories = res.data.categories || [];
      globalLocations = res.data.locations || [];
      await DB.cacheProducts(globalProducts);
      populateSelectOptions();
    }
  } catch (e) {
    // ከመስመር ውጭ ከሆነ ከአካባቢው ዳታቤዝ ማምጣት
    globalProducts = await DB.getLocalProducts();
    populateSelectOptions();
  }

  // አካውንቶች እና እውቂያዎችን መጫን
  try {
    const resCnt = await API.request('contacts.php');
    if (resCnt.success && resCnt.data) {
      globalContacts = resCnt.data.contacts || [];
      populateContactSelects();
    }
  } catch (e) {}
}

function populateSelectOptions() {
  // 1. የምርት ምድቦች
  const catSel = document.getElementById('np-category');
  if (catSel) {
    catSel.innerHTML = globalCategories.map(c => `<option value="${c.id}">${c.name_am} (${c.name_en})</option>`).join('');
  }

  // 2. መጋዘኖች
  const locSelectors = ['os-location', 'adj-location', 'pur-location'];
  for (const id of locSelectors) {
    const el = document.getElementById(id);
    if (el) {
      el.innerHTML = globalLocations.map(l => `<option value="${l.id}">${l.name_am}</option>`).join('');
    }
  }

  // 3. ምርቶች መራጮች
  const prodSelectors = ['os-product', 'adj-product', 'pur-product', 'fp-product', 'rcp-ingredient'];
  for (const id of prodSelectors) {
    const el = document.getElementById(id);
    if (el) {
      el.innerHTML = globalProducts.map(p => `<option value="${p.id}">${p.name_am} (${p.unit})</option>`).join('');
    }
  }

  // 4. የእርሻ ምርቶች (ለዝውውር)
  const trfProdSel = document.getElementById('trf-product');
  if (trfProdSel) {
    const farmProds = globalProducts.filter(p => p.source === 'farm');
    trfProdSel.innerHTML = farmProds.map(p => `<option value="${p.id}">${p.name_am} (ያለ: ${p.total_stock} ${p.unit})</option>`).join('');
  }

  // 5. የሬስቶራንት ምግቦች (ለ Recipe)
  const rcpMealSel = document.getElementById('rcp-meal');
  if (rcpMealSel) {
    const restMeals = globalProducts.filter(p => p.category_id === 4);
    rcpMealSel.innerHTML = restMeals.map(p => `<option value="${p.id}">${p.name_am}</option>`).join('');
  }
}

function populateContactSelects() {
  const custSel = document.getElementById('pos-customer-select');
  if (custSel) {
    const customers = globalContacts.filter(c => c.type === 'customer');
    custSel.innerHTML = `<option value="">-- ተራ ደንበኛ (Walk-in Customer) --</option>` +
      customers.map(c => `<option value="${c.id}">${c.name} (${c.phone || 'ስልክ የለም'})</option>`).join('');
  }

  const supSel = document.getElementById('pur-supplier');
  if (supSel) {
    const suppliers = globalContacts.filter(c => c.type === 'supplier');
    supSel.innerHTML = suppliers.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
  }

  const debtContactSel = document.getElementById('debt-contact');
  if (debtContactSel) {
    debtContactSel.innerHTML = globalContacts.map(c => `<option value="${c.id}">${c.name} [${c.type === 'customer' ? 'ደንበኛ' : 'አቅራቢ'}] (ዕዳ: ${c.outstanding_balance} ETB)</option>`).join('');
  }
}

// 5. ዳሽቦርድ መጫን (Dashboard Loader)
async function loadDashboard(filter = 'today', btn = null) {
  if (btn) {
    btn.parentElement.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }

  try {
    const res = await API.request(`dashboard.php?filter=${filter}`);
    if (res.success && res.data) {
      const d = res.data;

      // ሚዛኖች
      document.getElementById('dash-total-liquid').textContent = formatETB(d.actual_balances.total_liquid);
      document.getElementById('dash-cash').textContent = formatETB(d.actual_balances.cash);
      document.getElementById('dash-cbe').textContent = formatETB(d.actual_balances.cbe);
      document.getElementById('dash-telebirr').textContent = formatETB(d.actual_balances.telebirr);
      document.getElementById('dash-otherbanks').textContent = formatETB(d.actual_balances.other_banks);

      // ስራ ክንውኖች
      document.getElementById('dash-sales').textContent = formatETB(d.operations.total_sales);
      document.getElementById('dash-purchases').textContent = formatETB(d.operations.total_purchases);
      document.getElementById('dash-expenses').textContent = formatETB(d.operations.total_expenses);
      document.getElementById('dash-withdrawals').textContent = formatETB(d.operations.total_withdrawals);
      document.getElementById('dash-gross-profit').textContent = formatETB(d.operations.gross_profit);
      document.getElementById('dash-net-profit').textContent = formatETB(d.operations.net_profit);

      // ክምችት ዋጋ
      document.getElementById('dash-stock-value').textContent = formatETB(d.stock.total_value);

      // ዝቅተኛ ክምችት ማስጠንቀቂያ
      const lowStockBox = document.getElementById('dash-low-stock-container');
      if (d.stock.low_stock_products.length > 0) {
        lowStockBox.innerHTML = d.stock.low_stock_products.map(p => `
          <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed var(--border-color); font-size:0.85rem;">
            <span>⚠️ <strong>${p.name_am}</strong></span>
            <span style="color:var(--danger); font-weight:700;">ቀሪ: ${p.current_stock} ${p.unit} (ዝቅተኛ: ${p.min_stock_level})</span>
          </div>
        `).join('');
      } else {
        lowStockBox.innerHTML = `<p style="color:var(--text-muted); font-size:0.85rem;">ዝቅተኛ ክምችት ያላቸው እቃዎች የሉም</p>`;
      }

      // ዕዳዎች
      document.getElementById('dash-supplier-debt').textContent = formatETB(d.debts.supplier_debt);
      document.getElementById('dash-customer-debt').textContent = formatETB(d.debts.customer_debt);

      // አካውንቶች መራጭ መሙላት
      if (d.accounts) {
        globalAccounts = d.accounts;
        const accSelectors = ['exp-account', 'wth-account', 'pur-account', 'debt-account'];
        for (const sid of accSelectors) {
          const el = document.getElementById(sid);
          if (el) {
            el.innerHTML = globalAccounts.map(a => `<option value="${a.id}">${a.name_am} (ያለ: ${formatETB(a.current_balance)})</option>`).join('');
          }
        }
      }
    }
  } catch (e) {
    console.warn('Dashboard load fallback:', e);
  }
}

// 6. የሬስቶራንት እና ምርት ሽያጭ POS (POS Controller)
function renderPosProducts() {
  const container = document.getElementById('pos-products-grid');
  if (!container) return;

  const query = (document.getElementById('pos-search-input')?.value || '').toLowerCase().trim();
  const filtered = globalProducts.filter(p => {
    return p.is_active && (p.name_am.toLowerCase().includes(query) || p.name_en.toLowerCase().includes(query));
  });

  if (filtered.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); padding:12px; grid-column:span 2; text-align:center;">ምንም ምርት አልተገኘም</p>`;
    return;
  }

  container.innerHTML = filtered.map(p => `
    <div class="product-pos-card" onclick="addToCart(${p.id})">
      <div>
        <div class="product-pos-name">${p.name_am}</div>
        <div class="product-pos-meta">${p.name_en} • ${p.unit}</div>
      </div>
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span class="product-pos-price">${formatETB(p.selling_price)}</span>
        <span class="badge-tag ${p.source}">${p.source === 'farm' ? 'እርሻ' : (p.source === 'restaurant' ? 'ምግብ' : 'ውጭ')}</span>
      </div>
    </div>
  `).join('');
}

function addToCart(productId) {
  const prod = globalProducts.find(p => p.id === productId);
  if (!prod) return;

  const existing = globalCart.find(item => item.product.id === productId);
  if (existing) {
    existing.quantity += 1;
  } else {
    globalCart.push({ product: prod, quantity: 1 });
  }

  renderCart();
}

function changeCartQty(productId, delta) {
  const idx = globalCart.findIndex(item => item.product.id === productId);
  if (idx !== -1) {
    globalCart[idx].quantity += delta;
    if (globalCart[idx].quantity <= 0) {
      globalCart.splice(idx, 1);
    }
  }
  renderCart();
}

function clearCart() {
  globalCart = [];
  renderCart();
}

function renderCart() {
  const container = document.getElementById('cart-items-list');
  const countEl = document.getElementById('cart-items-count');
  if (!container) return;

  if (globalCart.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:12px;" data-i18n="emptyCart">ምንም የተመረጠ ዕቃ የለም</p>`;
    if (countEl) countEl.textContent = '0';
    updateCartTotals();
    return;
  }

  let totalCount = 0;
  container.innerHTML = globalCart.map(item => {
    totalCount += item.quantity;
    const itemTotal = item.quantity * item.product.selling_price;
    return `
      <div class="cart-item">
        <div>
          <div style="font-weight:700; font-size:0.9rem;">${item.product.name_am}</div>
          <div style="font-size:0.75rem; color:var(--text-muted);">${formatETB(item.product.selling_price)} x ${item.quantity} = <strong>${formatETB(itemTotal)}</strong></div>
        </div>
        <div class="cart-qty-ctrl">
          <button class="qty-btn" onclick="changeCartQty(${item.product.id}, -1)">-</button>
          <span style="font-weight:700; min-width:24px; text-align:center;">${item.quantity}</span>
          <button class="qty-btn" onclick="changeCartQty(${item.product.id}, 1)">+</button>
        </div>
      </div>
    `;
  }).join('');

  if (countEl) countEl.textContent = totalCount;
  updateCartTotals();
}

function updateCartTotals() {
  let subtotal = 0;
  for (const item of globalCart) {
    subtotal += item.quantity * item.product.selling_price;
  }

  const discount = Math.max(0, parseFloat(document.getElementById('cart-discount')?.value || 0));
  const grandTotal = Math.max(0, subtotal - discount);

  const subEl = document.getElementById('cart-subtotal');
  const grandEl = document.getElementById('cart-grand-total');

  if (subEl) subEl.textContent = formatETB(subtotal);
  if (grandEl) grandEl.textContent = formatETB(grandTotal);
}

function togglePosPaymentDetails() {
  const method = document.getElementById('pos-payment-method').value;
  const mixedBox = document.getElementById('pos-mixed-payment-box');
  const custBox = document.getElementById('pos-customer-box');

  if (mixedBox) mixedBox.style.display = (method === 'mixed') ? 'block' : 'none';
  if (custBox) custBox.style.display = (method === 'credit') ? 'block' : 'block';
}

// 7. ሽያጭ ማጠናቀቅ (Submit Sale)
async function submitSale() {
  if (globalCart.length === 0) {
    alert('እባክዎ የሚሸጥ ዕቃ ይምረጡ');
    return;
  }

  let subtotal = 0;
  const items = globalCart.map(it => {
    subtotal += it.quantity * it.product.selling_price;
    return {
      product_id: it.product.id,
      quantity: it.quantity,
      unit_price: it.product.selling_price,
      total_price: it.quantity * it.product.selling_price
    };
  });

  const discount = Math.max(0, parseFloat(document.getElementById('cart-discount')?.value || 0));
  const grandTotal = Math.max(0, subtotal - discount);
  const paymentMethod = document.getElementById('pos-payment-method').value;
  const customerId = document.getElementById('pos-customer-select').value || null;

  if (paymentMethod === 'credit' && !customerId) {
    alert('በብድር ለመሸጥ ደንበኛ መምረጥ ግዴታ ነው!');
    return;
  }

  // ክፍያዎችን ማዘጋጀት
  let payments = [];
  const cashAcc = globalAccounts.find(a => a.code === 'cash')?.id || 1;
  const teleAcc = globalAccounts.find(a => a.code === 'telebirr')?.id || 3;
  const cbeAcc  = globalAccounts.find(a => a.code === 'cbe')?.id || 2;

  if (paymentMethod === 'cash') {
    payments.push({ account_id: cashAcc, amount: grandTotal });
  } else if (paymentMethod === 'telebirr') {
    payments.push({ account_id: teleAcc, amount: grandTotal });
  } else if (paymentMethod === 'cbe') {
    payments.push({ account_id: cbeAcc, amount: grandTotal });
  } else if (paymentMethod === 'mixed') {
    const mCash = parseFloat(document.getElementById('pos-mixed-cash')?.value || 0);
    const mTele = parseFloat(document.getElementById('pos-mixed-telebirr')?.value || 0);
    const mCbe  = parseFloat(document.getElementById('pos-mixed-cbe')?.value || 0);
    if (mCash > 0) payments.push({ account_id: cashAcc, amount: mCash });
    if (mTele > 0) payments.push({ account_id: teleAcc, amount: mTele });
    if (mCbe > 0)  payments.push({ account_id: cbeAcc, amount: mCbe });
  }

  const txUuid = 'sal-' + Date.now() + '-' + Math.random().toString(36).substring(2, 8);
  const payload = {
    uuid: txUuid,
    customer_id: customerId ? parseInt(customerId) : null,
    sale_date: new Date().toISOString().replace('T', ' ').substring(0, 19),
    subtotal: subtotal,
    discount: discount,
    grand_total: grandTotal,
    payment_status: paymentMethod === 'credit' ? 'credit' : 'paid',
    items: items,
    payments: payments
  };

  // ከመስመር ውጭ ወይም መስመር ላይ መላክ
  if (!navigator.onLine) {
    // ከመስመር ውጭ: በ IndexedDB መመዝገብ
    await DB.enqueueTransaction('sale', payload);
    // የአካባቢውን ክምችት መቀነስ
    for (const it of globalCart) {
      await DB.adjustLocalProductStock(it.product.id, -it.quantity);
      it.product.total_stock = (it.product.total_stock || 0) - it.quantity;
    }
    API.updateStatusBadge();
    showReceipt({ ...payload, receipt_number: 'OFFLINE-' + txUuid.substring(4, 10), is_offline: true });
    clearCart();
    alert('ሽያጩ ከመስመር ውጭ ተመዝግቧል! ኢንተርኔት ሲመጣ በራስ-ሰር ይመሳሰላል።');
  } else {
    // የመስመር ላይ: በቀጥታ ወደ ሰርቨር መላክ
    try {
      const res = await API.request('sales.php?action=create', {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      if (res.success && res.data) {
        showReceipt({ ...payload, receipt_number: res.data.receipt_number });
        clearCart();
        loadInitialData(); // ክምችት ማደስ
      } else {
        alert(res.error || 'ሽያጩ አልተሳካም');
      }
    } catch (e) {
      // ኔትወርክ ከተቋረጠ ወዲያው ወደ IndexedDB ማዛወር
      await DB.enqueueTransaction('sale', payload);
      API.updateStatusBadge();
      showReceipt({ ...payload, receipt_number: 'OFFLINE-' + txUuid.substring(4, 10), is_offline: true });
      clearCart();
      alert('ኔትወርክ ስለተቋረጠ ሽያጩ በአካባቢው ተመዝግቧል');
    }
  }
}

function showReceipt(data) {
  const container = document.getElementById('receipt-print-area');
  if (!container) return;

  let itemsHtml = '';
  if (data.items) {
    for (const it of data.items) {
      const p = globalProducts.find(prod => prod.id === it.product_id);
      const name = p ? p.name_am : 'ምርት';
      itemsHtml += `
        <div class="receipt-row">
          <span>${name} (${it.quantity} x ${formatETB(it.unit_price)})</span>
          <span>${formatETB(it.total_price)}</span>
        </div>
      `;
    }
  }

  container.innerHTML = `
    <div class="receipt-header">
      <h3 style="margin-bottom:2px;">${I18N.t('appName')}</h3>
      <p style="font-size:0.75rem; color:#555;">ቢሾፍቱ፣ ኢትዮጵያ • ስልክ: +251 911 00 00 00</p>
      <div class="receipt-divider"></div>
      <div><strong>ደረሰኝ #: ${data.receipt_number}</strong></div>
      <div>ቀን: ${data.sale_date}</div>
      ${data.is_offline ? '<div style="color:var(--warning); font-weight:700;">[ከመስመር ውጭ የተከናወነ]</div>' : ''}
    </div>
    <div class="receipt-divider"></div>
    ${itemsHtml}
    <div class="receipt-divider"></div>
    <div class="receipt-row"><span>ንዑስ ድምር:</span><span>${formatETB(data.subtotal)}</span></div>
    <div class="receipt-row"><span>ቅናሽ:</span><span>-${formatETB(data.discount)}</span></div>
    <div class="receipt-row" style="font-size:1rem; font-weight:800;"><span>ጠቅላላ ድምር:</span><span>${formatETB(data.grand_total)}</span></div>
    <div class="receipt-divider"></div>
    <div style="text-align:center; font-size:0.8rem; margin-top:8px;">ስለመረጡን እናመሰግናለን! እንደገና ይጎብኙን!</div>
  `;

  openModal('modal-receipt');
}

// 8. ክምችት እና ምርቶች መቆጣጠሪያ (Stock Table & Handlers)
async function loadStock() {
  await loadInitialData();
  renderStockTable();
}

function renderStockTable() {
  const tbody = document.getElementById('stock-table-body');
  const countEl = document.getElementById('products-count');
  if (!tbody) return;

  const query = (document.getElementById('stock-search-input')?.value || '').toLowerCase().trim();
  const filtered = globalProducts.filter(p => {
    return p.name_am.toLowerCase().includes(query) || p.name_en.toLowerCase().includes(query) || (p.category_name_am || '').toLowerCase().includes(query);
  });

  if (countEl) countEl.textContent = `${filtered.length} ምርቶች`;

  tbody.innerHTML = filtered.map(p => {
    let locStockText = '';
    if (p.stock_by_location && p.stock_by_location.length > 0) {
      locStockText = p.stock_by_location.map(l => `${l.location_name_am}: <strong>${l.quantity}</strong>`).join(' | ');
    } else {
      locStockText = 'መረጃ የለም';
    }

    const isLow = p.min_stock_level > 0 && p.total_stock <= p.min_stock_level;

    return `
      <tr>
        <td>
          <div style="font-weight:700;">${p.name_am}</div>
          <div style="font-size:0.75rem; color:var(--text-muted);">${p.name_en}</div>
        </td>
        <td>${p.unit}</td>
        <td><strong>${formatETB(p.selling_price)}</strong></td>
        <td style="${isLow ? 'color:var(--danger); font-weight:800;' : 'font-weight:700;'}">
          ${p.total_stock} ${p.unit} ${isLow ? '⚠️' : ''}
        </td>
        <td style="font-size:0.75rem;">${locStockText}</td>
      </tr>
    `;
  }).join('');
}

// አዲስ ምርት መመዝገብ
async function handleCreateProduct(e) {
  e.preventDefault();
  const payload = {
    name_am: document.getElementById('np-name-am').value.trim(),
    name_en: document.getElementById('np-name-en').value.trim(),
    category_id: parseInt(document.getElementById('np-category').value),
    unit: document.getElementById('np-unit').value,
    selling_price: parseFloat(document.getElementById('np-selling-price').value),
    purchase_cost: parseFloat(document.getElementById('np-purchase-cost').value || 0),
    min_stock_level: parseFloat(document.getElementById('np-min-stock').value || 0),
    source: document.getElementById('np-source').value
  };

  try {
    const res = await API.request('products.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('ምርቱ በተሳካ ሁኔታ ተመዝግቧል (ክምችት አልተጨመረም)');
      closeModal('modal-new-product');
      loadInitialData();
      renderStockTable();
    } else {
      alert(res.error || 'ስህተት ተከስቷል');
    }
  } catch (err) {
    alert(err.message);
  }
}

// የመክፈቻ ክምችት ማስገባት
async function handleOpeningStock(e) {
  e.preventDefault();
  const payload = {
    product_id: parseInt(document.getElementById('os-product').value),
    location_id: parseInt(document.getElementById('os-location').value),
    quantity: parseFloat(document.getElementById('os-qty').value),
    unit_cost: parseFloat(document.getElementById('os-cost').value || 0)
  };

  try {
    const res = await API.request('products.php?action=opening_stock', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('የመክፈቻ ክምችት ገብቷል');
      closeModal('modal-opening-stock');
      loadInitialData();
      renderStockTable();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

// የክምችት ማስተካከያ
async function handleAdjustStock(e) {
  e.preventDefault();
  const payload = {
    product_id: parseInt(document.getElementById('adj-product').value),
    location_id: parseInt(document.getElementById('adj-location').value),
    adjustment_type: document.getElementById('adj-type').value,
    quantity: parseFloat(document.getElementById('adj-qty').value),
    reason: document.getElementById('adj-reason').value.trim()
  };

  try {
    const res = await API.request('products.php?action=adjust_stock', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('ማስተካከያው ተመዝግቧል');
      closeModal('modal-adjust-stock');
      loadInitialData();
      renderStockTable();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

// 9. ወጪዎች እና መውጣቶች (Expenses & Withdrawals)
async function loadExpenses() {
  try {
    const res = await API.request('expenses.php');
    if (res.success && res.data) {
      const expenses = res.data.expenses || [];
      let total = 0;
      const tbody = document.getElementById('expenses-table-body');
      if (tbody) {
        tbody.innerHTML = expenses.map(exp => {
          total += exp.amount;
          return `
            <tr>
              <td>${exp.expense_date}</td>
              <td>${exp.category_name_am}</td>
              <td>${exp.description}</td>
              <td>${exp.account_name_am}</td>
              <td style="color:var(--danger); font-weight:700;">-${formatETB(exp.amount)}</td>
            </tr>
          `;
        }).join('');
      }
      const badge = document.getElementById('expenses-total-badge');
      if (badge) badge.textContent = formatETB(total);
    }
  } catch (e) {}
}

async function handleCreateExpense(e) {
  e.preventDefault();
  const payload = {
    category_id: parseInt(document.getElementById('exp-category').value),
    account_id: parseInt(document.getElementById('exp-account').value),
    amount: parseFloat(document.getElementById('exp-amount').value),
    department: document.getElementById('exp-dept').value,
    description: document.getElementById('exp-desc').value.trim(),
    expense_date: new Date().toISOString().substring(0, 10)
  };

  if (!navigator.onLine) {
    await DB.enqueueTransaction('expense', payload);
    API.updateStatusBadge();
    closeModal('modal-new-expense');
    alert('ወጪው ከመስመር ውጭ ተመዝግቧል!');
    return;
  }

  try {
    const res = await API.request('expenses.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('ወጪው ተመዝግቧል');
      closeModal('modal-new-expense');
      loadExpenses();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

async function loadWithdrawals() {
  try {
    const res = await API.request('withdrawals.php');
    if (res.success && res.data) {
      const withdrawals = res.data.withdrawals || [];
      let total = 0;
      const tbody = document.getElementById('withdrawals-table-body');
      if (tbody) {
        tbody.innerHTML = withdrawals.map(w => {
          total += w.amount;
          return `
            <tr>
              <td>${w.withdrawal_date}</td>
              <td><strong>${w.recipient_name}</strong></td>
              <td>${w.reason}</td>
              <td>${w.account_name_am}</td>
              <td style="color:var(--warning); font-weight:700;">-${formatETB(w.amount)}</td>
            </tr>
          `;
        }).join('');
      }
      const badge = document.getElementById('withdrawals-total-badge');
      if (badge) badge.textContent = formatETB(total);
    }
  } catch (e) {}
}

async function handleCreateWithdrawal(e) {
  e.preventDefault();
  const payload = {
    account_id: parseInt(document.getElementById('wth-account').value),
    amount: parseFloat(document.getElementById('wth-amount').value),
    recipient_name: document.getElementById('wth-recipient').value.trim(),
    reason: document.getElementById('wth-reason').value.trim(),
    withdrawal_date: new Date().toISOString().substring(0, 10)
  };

  if (!navigator.onLine) {
    await DB.enqueueTransaction('withdrawal', payload);
    API.updateStatusBadge();
    closeModal('modal-new-withdrawal');
    alert('ገንዘብ መውጣቱ ከመስመር ውጭ ተመዝግቧል!');
    return;
  }

  try {
    const res = await API.request('withdrawals.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('ገንዘብ መውጣቱ ተመዝግቧል (የስራ ማስኬጃ ወጪ ተደርጎ አልተቆጠረም)');
      closeModal('modal-new-withdrawal');
      loadWithdrawals();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

// 10. ከእርሻ ወደ ሬስቶራንት ዝውውር (Transfers)
async function loadTransfers() {
  try {
    const res = await API.request('transfers.php');
    if (res.success && res.data) {
      const transfers = res.data.transfers || [];
      const tbody = document.getElementById('transfers-table-body');
      if (tbody) {
        tbody.innerHTML = transfers.map(tr => `
          <tr>
            <td>${tr.transfer_date.substring(0, 10)}</td>
            <td><strong>${tr.product_name_am}</strong></td>
            <td>${tr.quantity} ${tr.unit}</td>
            <td>${tr.from_location_name_am}</td>
            <td>${tr.to_location_name_am}</td>
            <td>
              <span class="badge-tag ${tr.status === 'received' ? 'farm' : (tr.status === 'pending' ? 'external' : 'restaurant')}">
                ${tr.status === 'received' ? 'ተቀባይነት አግኝቷል' : (tr.status === 'pending' ? 'በመጠባበቅ ላይ' : 'ውድቅ')}
              </span>
            </td>
            <td>
              ${tr.status === 'pending' ? `
                <button class="btn btn-primary btn-sm" onclick="receiveTransfer(${tr.id})">ተቀበል</button>
                <button class="btn btn-danger btn-sm" onclick="rejectTransfer(${tr.id})">ውድቅ</button>
              ` : '—'}
            </td>
          </tr>
        `).join('');
      }
    }
  } catch (e) {}
}

async function handleCreateTransfer(e) {
  e.preventDefault();
  const payload = {
    product_id: parseInt(document.getElementById('trf-product').value),
    quantity: parseFloat(document.getElementById('trf-qty').value),
    notes: document.getElementById('trf-notes').value.trim(),
    from_location_id: 1, // የእርሻ መጋዘን
    to_location_id: 2   // የሬስቶራንት ክምችት
  };

  try {
    const res = await API.request('transfers.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('ዝውውሩ ተልኳል፤ ሬስቶራንቱ እስኪያረጋግጥ ይጠብቃል');
      closeModal('modal-new-transfer');
      loadTransfers();
      loadInitialData();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

async function receiveTransfer(transferId) {
  if (!confirm('ይህንን ምርት ተቀብለው ወደ ሬስቶራንቱ ክምችት ማስገባት ይፈልጋሉ?')) return;
  try {
    const res = await API.request('transfers.php?action=receive', {
      method: 'POST',
      body: JSON.stringify({ transfer_id: transferId })
    });
    if (res.success) {
      alert(res.message);
      loadTransfers();
      loadInitialData();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

async function rejectTransfer(transferId) {
  const reason = prompt('ውድቅ የተደረገበትን ምክንያት ያስገቡ:');
  if (!reason) return;
  try {
    const res = await API.request('transfers.php?action=reject', {
      method: 'POST',
      body: JSON.stringify({ transfer_id: transferId, reason })
    });
    if (res.success) {
      alert('ዝውውሩ ውድቅ ተደርጓል');
      loadTransfers();
    }
  } catch (err) {
    alert(err.message);
  }
}

// 11. የእርሻ እና ወተት ምርት ማቀነባበሪያ (Farm Productions)
async function loadFarmProductions() {
  try {
    const res = await API.request('farm.php');
    if (res.success && res.data) {
      const productions = res.data.productions || [];
      const tbody = document.getElementById('farm-table-body');
      if (tbody) {
        tbody.innerHTML = productions.map(fp => `
          <tr>
            <td>${fp.production_date}</td>
            <td><strong>${fp.product_name_am}</strong></td>
            <td>${fp.quantity} ${fp.unit}</td>
            <td>${formatETB(fp.production_cost)}</td>
            <td>${fp.batch_number || '—'}</td>
          </tr>
        `).join('');
      }
    }
  } catch (e) {}
}

async function handleCreateFarmProduction(e) {
  e.preventDefault();
  const payload = {
    product_id: parseInt(document.getElementById('fp-product').value),
    quantity: parseFloat(document.getElementById('fp-qty').value),
    production_cost: parseFloat(document.getElementById('fp-cost').value || 0),
    expiry_date: document.getElementById('fp-expiry').value || null,
    production_date: new Date().toISOString().substring(0, 10),
    destination_location_id: 1 // የእርሻ መጋዘን
  };

  if (!navigator.onLine) {
    await DB.enqueueTransaction('production', payload);
    API.updateStatusBadge();
    closeModal('modal-new-farm');
    alert('ምርቱ ከመስመር ውጭ ተመዝግቧል!');
    return;
  }

  try {
    const res = await API.request('farm.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert(res.message);
      closeModal('modal-new-farm');
      loadFarmProductions();
      loadInitialData();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

// 12. የግዢ መዝገብ (Purchases)
async function loadPurchases() {
  try {
    const res = await API.request('purchases.php');
    if (res.success && res.data) {
      const purchases = res.data.purchases || [];
      const tbody = document.getElementById('purchases-table-body');
      if (tbody) {
        tbody.innerHTML = purchases.map(pur => `
          <tr>
            <td>${pur.purchase_date}</td>
            <td><strong>${pur.supplier_name || 'የውጭ አቅራቢ'}</strong></td>
            <td>${formatETB(pur.grand_total)}</td>
            <td>
              <span class="badge-tag ${pur.payment_status === 'paid' ? 'farm' : (pur.payment_status === 'partial' ? 'external' : 'restaurant')}">
                ${pur.payment_status === 'paid' ? 'ሙሉ ተከፍሏል' : (pur.payment_status === 'partial' ? 'ከፊል' : 'በብድር')}
              </span>
            </td>
            <td>${pur.items?.length || 0} እቃዎች</td>
          </tr>
        `).join('');
      }
    }
  } catch (e) {}
}

async function handleCreatePurchase(e) {
  e.preventDefault();
  const prodId = parseInt(document.getElementById('pur-product').value);
  const locId  = parseInt(document.getElementById('pur-location').value);
  const qty    = parseFloat(document.getElementById('pur-qty').value);
  const price  = parseFloat(document.getElementById('pur-price').value);
  const addCost= parseFloat(document.getElementById('pur-add-cost').value || 0);
  const paid   = parseFloat(document.getElementById('pur-paid').value);
  const supId  = document.getElementById('pur-supplier').value || null;
  const accId  = parseInt(document.getElementById('pur-account').value);

  const payload = {
    supplier_id: supId ? parseInt(supId) : null,
    purchase_date: new Date().toISOString().substring(0, 10),
    additional_cost: addCost,
    items: [{
      product_id: prodId,
      location_id: locId,
      quantity: qty,
      unit_price: price,
      total_price: qty * price
    }],
    payments: paid > 0 ? [{ account_id: accId, amount: paid }] : []
  };

  if (!navigator.onLine) {
    await DB.enqueueTransaction('purchase', payload);
    API.updateStatusBadge();
    closeModal('modal-new-purchase');
    alert('ግዢው ከመስመር ውጭ ተመዝግቧል!');
    return;
  }

  try {
    const res = await API.request('purchases.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert(res.message);
      closeModal('modal-new-purchase');
      loadPurchases();
      loadInitialData();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

// 13. የምግብ አዘገጃጀት (Recipes)
async function loadRecipes() {
  try {
    const res = await API.request('recipes.php');
    if (res.success && res.data) {
      const recipes = res.data.recipes || [];
      const tbody = document.getElementById('recipes-table-body');
      if (tbody) {
        tbody.innerHTML = recipes.map(r => `
          <tr>
            <td><strong>${r.parent_product_name_am}</strong></td>
            <td>${r.ingredient_name_am}</td>
            <td>${r.quantity_needed} ${r.unit}</td>
          </tr>
        `).join('');
      }
    }
  } catch (e) {}
}

async function handleCreateRecipe(e) {
  e.preventDefault();
  const payload = {
    parent_product_id: parseInt(document.getElementById('rcp-meal').value),
    ingredient_product_id: parseInt(document.getElementById('rcp-ingredient').value),
    quantity_needed: parseFloat(document.getElementById('rcp-qty').value),
    unit: 'kg'
  };

  try {
    const res = await API.request('recipes.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('የምግብ አዘገጃጀቱ ተገናኝቷል');
      closeModal('modal-new-recipe');
      loadRecipes();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

// 14. ዕለታዊ ሂሳብ መዝጊያ (Daily Closing)
async function loadDailyClosing() {
  try {
    const res = await API.request('daily_closing.php?action=status');
    if (res.success && res.data) {
      const d = res.data;
      const statusBadge = document.getElementById('closing-status-badge');
      const reopenSec = document.getElementById('reopen-closing-section');
      const sysCashInput = document.getElementById('closing-system-cash');

      if (sysCashInput) sysCashInput.value = formatETB(d.system_balances.cash);

      if (d.is_closed) {
        statusBadge.textContent = 'የተዘጋ (Closed)';
        statusBadge.className = 'badge-tag restaurant';
        if (reopenSec && API.user?.role === 'super_admin') reopenSec.style.display = 'block';
      } else {
        statusBadge.textContent = 'ክፍት (Open)';
        statusBadge.className = 'badge-tag farm';
        if (reopenSec) reopenSec.style.display = 'none';
      }
    }
  } catch (e) {}
}

function calculateClosingDiff() {
  const actual = parseFloat(document.getElementById('closing-actual-cash')?.value || 0);
  const sysRaw = (document.getElementById('closing-system-cash')?.value || '0').replace(/[^0-9.-]+/g, '');
  const sys = parseFloat(sysRaw || 0);
  const diff = actual - sys;

  const diffEl = document.getElementById('closing-diff');
  if (diffEl) {
    diffEl.value = `${diff >= 0 ? '+' : ''}${formatETB(diff)}`;
    diffEl.style.color = Math.abs(diff) < 0.01 ? 'var(--success)' : 'var(--danger)';
  }
}

async function submitDailyClosing() {
  const actualCash = parseFloat(document.getElementById('closing-actual-cash').value);
  const actualCbe  = parseFloat(document.getElementById('closing-actual-cbe').value || 0);
  const actualTele = parseFloat(document.getElementById('closing-actual-telebirr').value || 0);
  const reason     = document.getElementById('closing-discrepancy-reason').value.trim();

  if (isNaN(actualCash)) {
    alert('እባክዎ በእጅ ያለውን ትክክለኛ ጥሬ ገንዘብ ያስገቡ');
    return;
  }

  try {
    const res = await API.request('daily_closing.php?action=close', {
      method: 'POST',
      body: JSON.stringify({
        actual_cash: actualCash,
        actual_cbe: actualCbe,
        actual_telebirr: actualTele,
        discrepancy_reason: reason
      })
    });
    if (res.success) {
      alert('የዕለቱ ሂሳብ በተሳካ ሁኔታ ተዘግቷል');
      loadDailyClosing();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

async function reopenDailyClosing() {
  const reason = document.getElementById('closing-reopen-reason').value.trim();
  if (!reason) {
    alert('ዳግም የመክፈቻ ምክንያት ማስገባት ግዴታ ነው');
    return;
  }

  try {
    const res = await API.request('daily_closing.php?action=reopen', {
      method: 'POST',
      body: JSON.stringify({ reopened_reason: reason })
    });
    if (res.success) {
      alert('የዕለቱ ሂሳብ ዳግም ተከፍቷል');
      loadDailyClosing();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

// 15. ሪፖርቶች (Reports & P&L)
async function loadReport(reportType, btn = null) {
  if (btn) {
    btn.parentElement.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }

  const container = document.getElementById('reports-content-container');
  if (!container) return;

  container.innerHTML = `<p style="padding:12px; color:var(--text-muted);">${I18N.t('loading')}</p>`;

  try {
    const res = await API.request(`reports.php?type=${reportType}`);
    if (res.success && res.data) {
      if (reportType === 'pl') {
        const st = res.data.statement;
        container.innerHTML = `
          <div style="background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:14px; margin-top:10px;">
            <h4 style="color:var(--primary); margin-bottom:10px;">📊 የትርፍና ኪሳራ መግለጫ (Consolidated P&L)</h4>
            <div class="receipt-row"><span>ጠቅላላ የሽያጭ ገቢ:</span><strong>${formatETB(st.gross_revenue)}</strong></div>
            <div class="receipt-row"><span>የተሸጡ ዕቃዎች ወጪ (COGS):</span><span style="color:var(--danger);">-${formatETB(st.cost_of_goods_sold)}</span></div>
            <div class="receipt-divider"></div>
            <div class="receipt-row" style="font-weight:700; color:var(--primary);">
              <span>ጠቅላላ ትርፍ (Gross Profit):</span>
              <span>${formatETB(st.gross_profit)} (${st.gross_margin_percentage}%)</span>
            </div>
            <div class="receipt-row" style="margin-top:6px;"><span>የስራ ማስኬጃ ወጪዎች:</span><span style="color:var(--danger);">-${formatETB(st.operating_expenses)}</span></div>
            <div class="receipt-divider"></div>
            <div class="receipt-row" style="font-size:1.15rem; font-weight:800; color:#1e40af;">
              <span>የተጣራ ትርፍ (Net Profit):</span>
              <span>${formatETB(st.net_profit)} (${st.net_margin_percentage}%)</span>
            </div>
            <div class="receipt-divider"></div>
            <div class="receipt-row"><span>የባለቤት መውጣት (ካሽ የቀነሰ):</span><span>-${formatETB(st.owner_withdrawals)}</span></div>
            <p style="font-size:0.75rem; color:var(--text-muted); margin-top:8px;">${st.internal_transfers_note}</p>
          </div>
        `;
      } else if (reportType === 'product_profitability') {
        const prods = res.data.product_profitability || [];
        container.innerHTML = `
          <div class="table-responsive" style="margin-top:10px;">
            <table class="app-table">
              <thead>
                <tr><th>ምርት</th><th>የተሸጠ ብዛት</th><th>ገቢ</th><th>ወጪ</th><th>ትርፍ</th></tr>
              </thead>
              <tbody>
                ${prods.map(p => `
                  <tr>
                    <td><strong>${p.name_am}</strong></td>
                    <td>${p.sold_qty} ${p.unit}</td>
                    <td>${formatETB(p.total_sales)}</td>
                    <td>${formatETB(p.total_cost)}</td>
                    <td style="color:var(--success); font-weight:700;">${formatETB(p.profit)}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        `;
      } else if (reportType === 'cashflow') {
        const cf = res.data;
        container.innerHTML = `
          <div style="background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:14px; margin-top:10px;">
            <h4 style="color:var(--primary); margin-bottom:10px;">💵 የጥሬ ገንዘብ ፍሰት (Cash Flow)</h4>
            <div class="receipt-row"><span>ጠቅላላ የገባ ገንዘብ (Inflow):</span><strong style="color:var(--success);">${formatETB(cf.total_inflow)}</strong></div>
            <div class="receipt-row"><span>ጠቅላላ የወጣ ገንዘብ (Outflow):</span><strong style="color:var(--danger);">${formatETB(cf.total_outflow)}</strong></div>
            <div class="receipt-divider"></div>
            <div class="receipt-row" style="font-size:1.1rem; font-weight:800;">
              <span>የተጣራ የገንዘብ ፍሰት:</span>
              <span style="color:${cf.net_cash_flow >= 0 ? 'var(--success)' : 'var(--danger)'};">${formatETB(cf.net_cash_flow)}</span>
            </div>
          </div>
        `;
      }
    }
  } catch (e) {
    container.innerHTML = `<p style="color:var(--danger); padding:12px;">ሪፖርቱን መጫን አልተሳካም</p>`;
  }
}

// 16. ደንበኞችና አቅራቢዎች (Contacts & Debt)
async function loadContacts() {
  try {
    const res = await API.request('contacts.php');
    if (res.success && res.data) {
      globalContacts = res.data.contacts || [];
      populateContactSelects();
      const tbody = document.getElementById('contacts-table-body');
      if (tbody) {
        tbody.innerHTML = globalContacts.map(c => `
          <tr>
            <td><strong>${c.name}</strong></td>
            <td><span class="badge-tag ${c.type === 'customer' ? 'farm' : 'restaurant'}">${c.type === 'customer' ? 'ደንበኛ' : 'አቅራቢ'}</span></td>
            <td>${c.phone || '—'}</td>
            <td style="color:${c.outstanding_balance > 0 ? 'var(--danger)' : 'var(--text-main)'}; font-weight:700;">
              ${formatETB(c.outstanding_balance)}
            </td>
          </tr>
        `).join('');
      }
    }
  } catch (e) {}
}

async function handleCreateContact(e) {
  e.preventDefault();
  const payload = {
    name: document.getElementById('cnt-name').value.trim(),
    type: document.getElementById('cnt-type').value,
    phone: document.getElementById('cnt-phone').value.trim(),
    address: document.getElementById('cnt-address').value.trim()
  };

  try {
    const res = await API.request('contacts.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('እውቂያው ተመዝግቧል');
      closeModal('modal-new-contact');
      loadContacts();
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

async function handleSettleDebt(e) {
  e.preventDefault();
  const payload = {
    contact_id: parseInt(document.getElementById('debt-contact').value),
    account_id: parseInt(document.getElementById('debt-account').value),
    amount: parseFloat(document.getElementById('debt-amount').value),
    notes: document.getElementById('debt-notes').value.trim()
  };

  try {
    const res = await API.request('contacts.php?action=settle_debt', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert(res.message);
      closeModal('modal-settle-debt');
      loadContacts();
      loadDashboard('today');
    } else {
      alert(res.error);
    }
  } catch (err) {
    alert(err.message);
  }
}

// 17. ተጠቃሚዎች አስተዳደር (Users Management - Super Admin)
async function loadUsers() {
  try {
    const res = await API.request('auth.php?action=users');
    if (res.success && res.data) {
      const users = res.data.users || [];
      const tbody = document.getElementById('users-table-body');
      if (tbody) {
        tbody.innerHTML = users.map(u => `
          <tr>
            <td><strong>${u.full_name}</strong></td>
            <td>${u.username}</td>
            <td><span class="badge-tag ${u.role === 'super_admin' ? 'farm' : 'external'}">${u.role === 'super_admin' ? 'ዋና አስተዳዳሪ' : 'ስራ አስኪያጅ'}</span></td>
            <td>${u.is_active ? '✅ ንቁ' : '❌ የታገደ'}</td>
          </tr>
        `).join('');
      }
    }
  } catch (e) {}
}

// 18. ሞዳል ረዳቶች (Modal Helpers)
function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('active');
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('active');
}

// የኢትዮጵያ ብር አገላለጽ (Currency Formatter)
function formatETB(amount) {
  const val = parseFloat(amount || 0);
  return val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ETB';
}
