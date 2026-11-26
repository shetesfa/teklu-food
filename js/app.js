/**
 * ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
 * ዋና የተጠቃሚ በይነገጽ ቁጥጥር ሞተር (App Controller)
 */

// ግሎባል ተለዋዋጮች
let globalProducts = [];
let globalCategories = [];
let globalAccounts = [];
let globalSuppliers = [];
let globalCustomers = [];
let globalFoodRecipes = [];
let globalCart = []; // [{product, quantity}]
let currentSupplierStatementData = null;

// 1. መተግበሪያውን ማስነሳት (App Initialization)
window.addEventListener('DOMContentLoaded', async () => {
  API.init();
  I18N.applyTranslations();

  if (!API.token) {
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
    const titleEl = document.getElementById('app-title-el');
    if (titleEl) {
      titleEl.textContent = `ተክሉ ቢዝነስ (${user.full_name.split(' ')[0]})`;
    }
    // የፈቃድ ገደቦች
    if (user.role !== 'super_admin') {
      const userMgmtBtn = document.getElementById('btn-users-management');
      if (userMgmtBtn) userMgmtBtn.style.display = 'none';
    }
  }

  // የመነሻ ዳታዎችን መጫን
  loadInitialData();
  loadDashboard('today');
}

// 2. የመነሻ መረጃዎችን መጫን (Load Initial Base Data - Offline-First Cache & Sync)
async function loadInitialData() {
  try {
    // 1. መጀመሪያ ከአካባቢው (IndexedDB) በማንበብ ወዲያውኑ UI ማሳየት (Instant Local Render)
    const [localProds, localCats, localSups, localCusts, localAccs, localRecs] = await Promise.all([
      DB.getLocalProducts(),
      DB.getLocalCategories(),
      DB.getLocalContacts('supplier'),
      DB.getLocalContacts('customer'),
      DB.getLocalAccounts(),
      DB.getLocalRecipes()
    ]);

    if (localProds && localProds.length > 0) {
      globalProducts = localProds;
      globalCategories = localCats || [];
      populateCategoryDropdowns();
      renderPosProducts();
      renderStockTable();
    }
    if (localSups && localSups.length > 0) {
      globalSuppliers = localSups;
      populateSupplierDropdowns();
    }
    if (localCusts && localCusts.length > 0) {
      globalCustomers = localCusts;
      populateCustomerDropdowns();
    }
    if (localAccs && localAccs.length > 0) {
      globalAccounts = localAccs;
      populateAccountDropdowns();
    }
    if (localRecs && localRecs.length > 0) {
      globalFoodRecipes = localRecs;
      renderFoodRecipes(globalFoodRecipes);
    }

    // 2. ኦንላይን ከሆንን ከሰርቨር API አዲሱን ዳታ ማምጣት እና ማደስ
    if (API.isOnline) {
      // ምርቶች
      const prodRes = await API.request('products.php?action=list');
      if (prodRes.success) {
        globalProducts = prodRes.data.products || [];
        globalCategories = prodRes.data.categories || [];
        await DB.cacheProducts(globalProducts);
        await DB.cacheCategories(globalCategories);
        populateCategoryDropdowns();
        renderPosProducts();
        renderStockTable();
      }

      // አቅራቢዎች
      const supRes = await API.request('contacts.php?action=list&type=supplier');
      if (supRes.success) {
        globalSuppliers = supRes.data.contacts || [];
        await DB.cacheContacts(globalSuppliers);
        populateSupplierDropdowns();
      }

      // ደንበኞች
      const custRes = await API.request('contacts.php?action=list&type=customer');
      if (custRes.success) {
        globalCustomers = custRes.data.contacts || [];
        await DB.cacheContacts(globalCustomers);
        populateCustomerDropdowns();
      }

      // አካውንቶች
      const accRes = await API.request('withdrawals.php?action=list');
      if (accRes.success) {
        globalAccounts = accRes.data.accounts || [];
        await DB.cacheAccounts(globalAccounts);
        populateAccountDropdowns();
      }

      // ሬሲፒዎች
      const recRes = await API.request('recipes.php?action=list');
      if (recRes.success) {
        globalFoodRecipes = recRes.data.foods || [];
        await DB.cacheRecipes(globalFoodRecipes);
        renderFoodRecipes(globalFoodRecipes);
      }
    }
  } catch (e) {
    console.error('Initial data loading error:', e);
  }
}

// 3. ገጾችን መቀየር (Page Switching - Mobile & Desktop Synced)
function switchPage(pageId, btnEl) {
  // ሁሉንም ገጾች ደብቅ
  document.querySelectorAll('.page').forEach((p) => p.classList.remove('active'));

  // የተመረጠውን ገጽ አሳይ
  const target = document.getElementById(pageId);
  if (target) {
    target.classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // የታችኛው እና የጎን አሰሳ አዝራሮችን ማመሳሰል
  document.querySelectorAll('.bottom-nav .nav-item, .sidebar-nav .sidebar-link').forEach((b) => b.classList.remove('active'));

  if (btnEl) {
    btnEl.classList.add('active');
  }

  // ገጹ ሲቀየር ተዛማጅ ዳታ ማደስ
  if (pageId === 'page-dashboard') loadDashboard('today');
  else if (pageId === 'page-pos') renderPosProducts();
  else if (pageId === 'page-customer-debts') loadCustomerDebts();
  else if (pageId === 'page-receiving') loadReceivingData();
  else if (pageId === 'page-recipes') loadFoodRecipes();
  else if (pageId === 'page-stock') renderStockTable();
  else if (pageId === 'page-withdrawals') loadWithdrawals();
  else if (pageId === 'page-expenses') loadExpenses();
  else if (pageId === 'page-suppliers') loadSuppliers();
}

// 4. መግቢያ ማስተናገድ (Login)
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
    submitBtn.textContent = 'በመግባት ላይ...';
  }

  try {
    const res = await API.login(u, p);
    if (res.success) {
      initAppLayout();
    } else {
      if (errBox) {
        errBox.textContent = res.error || 'የተሳሳተ የተጠቃሚ ስም ወይም የይለፍ ቃል';
        errBox.style.display = 'block';
      }
    }
  } catch (err) {
    if (errBox) {
      errBox.textContent = 'የኔትወርክ ወይም የሰርቨር ስህተት: ' + err.message;
      errBox.style.display = 'block';
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
  if (role === 'admin') {
    uInput.value = 'admin';
    pInput.value = 'password';
  } else {
    uInput.value = 'manager';
    pInput.value = 'password';
  }
}

function togglePasswordVisibility() {
  const p = document.getElementById('login-password');
  p.type = p.type === 'password' ? 'text' : 'password';
}

// =========================================================================
// 5. ዳሽቦርድ (Dashboard Controller - Offline-First Cache & Sync)
// =========================================================================
async function loadDashboard(filter = 'today', btnEl = null, customStartDate = null, customEndDate = null) {
  if (btnEl) {
    btnEl.parentElement.querySelectorAll('.btn').forEach((b) => b.classList.remove('active'));
    btnEl.classList.add('active');
  }

  // ባጅ ማዘመን
  const badgeMap = {
    today: 'የዛሬ',
    yesterday: 'የትናንት',
    week: 'የዚህ ሳምንት',
    month: 'የዚህ ወር',
    last_month: 'ያለፈው ወር',
    custom: 'የተመረጠ የቀን ገደብ'
  };
  const badgeEl = document.getElementById('dash-period-badge');
  if (badgeEl) badgeEl.textContent = badgeMap[filter] || filter;

  // 1. መጀመሪያ ከአካባቢው (IndexedDB) በማንበብ ወዲያውኑ UI ማሳየት (Instant Local Render)
  const cachedDash = await DB.getLocalDashboard(filter);
  if (cachedDash) {
    renderDashboardUI(cachedDash);
  }

  // 2. ኦንላይን ከሆንን ከሰርቨር API አዲሱን ዳታ ማምጣት እና ማደስ
  if (API.isOnline) {
    try {
      let url = `dashboard.php?filter=${filter}`;
      if (filter === 'custom' && customStartDate && customEndDate) {
        url += `&start_date=${customStartDate}&end_date=${customEndDate}`;
      }
      const res = await API.request(url);
      if (res.success) {
        const d = res.data;
        renderDashboardUI(d);
        await DB.cacheDashboard(filter, d);
      }
    } catch (e) {
      console.error('Dashboard load error:', e);
    }
  }
}

function toggleCustomDateFilter(btnEl) {
  const box = document.getElementById('dash-custom-date-box');
  if (!box) return;
  const isHidden = box.style.display === 'none' || !box.style.display;
  box.style.display = isHidden ? 'block' : 'none';
  if (isHidden && btnEl) {
    btnEl.parentElement.querySelectorAll('.btn').forEach((b) => b.classList.remove('active'));
    btnEl.classList.add('active');
  }
}

async function applyCustomDateDashboard() {
  const start = document.getElementById('dash-start-date').value;
  const end = document.getElementById('dash-end-date').value;
  if (!start || !end) {
    alert('እባክዎ መነሻና መድረሻ ቀን ይምረጡ');
    return;
  }
  loadDashboard('custom', null, start, end);
}

function renderDashboardUI(d) {
  if (!d) return;

  // በእጅና በባንክ ያለ ገንዘብ
  if (d.actual_balances) {
    document.getElementById('dash-total-liquid').textContent = formatMoney(d.actual_balances.total_liquid);
    document.getElementById('dash-cash').textContent = formatMoney(d.actual_balances.cash);
    document.getElementById('dash-telebirr').textContent = formatMoney(d.actual_balances.telebirr);
    document.getElementById('dash-cbe').textContent = formatMoney(d.actual_balances.cbe);
    document.getElementById('dash-otherbanks').textContent = formatMoney(d.actual_balances.other_banks);
  }

  // የስራ ክንውኖች እና ትርፍ
  if (d.operations) {
    document.getElementById('dash-sales').textContent = formatMoney(d.operations.total_sales);
    document.getElementById('dash-purchases').textContent = formatMoney(d.operations.total_purchases);
    document.getElementById('dash-expenses').textContent = formatMoney(d.operations.total_expenses);
    document.getElementById('dash-withdrawals').textContent = formatMoney(d.operations.total_withdrawals);
    document.getElementById('dash-gross-profit').textContent = formatMoney(d.operations.gross_profit);
    document.getElementById('dash-net-profit').textContent = formatMoney(d.operations.net_profit);
  }

  // ክምችት
  if (d.stock) {
    document.getElementById('dash-stock-value').textContent = formatMoney(d.stock.total_value);
    renderLowStockAlerts(d.stock.low_stock_products || []);
  }

  // ዕዳዎች
  if (d.debts) {
    document.getElementById('dash-supplier-debt').textContent = formatMoney(d.debts.supplier_debt);
    document.getElementById('dash-customer-debt').textContent = formatMoney(d.debts.customer_debt);
  }
}

function renderLowStockAlerts(items) {
  const container = document.getElementById('dash-low-stock-container');
  if (!items || items.length === 0) {
    container.innerHTML = `<p style="color:#059669; font-size:0.82rem; text-align:center; padding:6px; background:#ecfdf5; border-radius:var(--radius-sm);">✅ ሁሉም ጥሬ እቃዎች በቂ ክምችት አላቸው</p>`;
    return;
  }

  let html = `<div style="display:flex; flex-direction:column; gap:6px;">`;
  items.forEach((it) => {
    html += `
      <div style="display:flex; justify-content:space-between; align-items:center; background:var(--warning-bg); border:1px solid #fde68a; padding:6px 10px; border-radius:var(--radius-sm); font-size:0.82rem;">
        <span style="font-weight:700; color:#92400e;">⚠️ ${it.name_am}</span>
        <span style="font-weight:800; color:#b45309;">ቀሪ፡ ${it.current_stock} ${it.unit} (ዝቅተኛ ማስጠንቀቂያ፡ ${it.min_stock_level})</span>
      </div>
    `;
  });
  html += `</div>`;
  container.innerHTML = html;
}

// =========================================================================
// 6. ሽያጭ / POS (Sales Controller)
// =========================================================================
let posCurrentCategory = 'all';

function filterPosCategory(cat, btnEl) {
  posCurrentCategory = cat;
  btnEl.parentElement.querySelectorAll('.btn').forEach((b) => b.classList.remove('active'));
  btnEl.classList.add('active');
  renderPosProducts();
}

function renderPosProducts() {
  const grid = document.getElementById('pos-products-grid');
  if (!grid) return;

  const search = (document.getElementById('pos-search-input')?.value || '').toLowerCase().trim();

  let filtered = globalProducts.filter((p) => {
    const matchSearch = p.name_am.toLowerCase().includes(search) || p.name_en.toLowerCase().includes(search);
    if (!matchSearch) return false;

    if (posCurrentCategory === 'all') return true;
    if (posCurrentCategory === 'food') return p.category_id === 4;
    if (posCurrentCategory === 'packaged') return p.category_id === 2;
    if (posCurrentCategory === 'beverage') return p.category_id === 3;
    return true;
  });

  if (filtered.length === 0) {
    grid.innerHTML = `<p style="grid-column:span 2; color:var(--text-muted); text-align:center; padding:16px;">ምንም የተገኘ ምርት የለም</p>`;
    return;
  }

  let html = '';
  filtered.forEach((p) => {
    const isOutOfStock = p.category_id !== 4 && p.total_stock <= 0;
    const stockText = p.category_id === 4 ? 'በሬሲፒ የሚዘጋጅ' : `ክምችት: ${p.total_stock} ${p.unit}`;

    html += `
      <div class="pos-prod-card ${isOutOfStock ? 'out-of-stock' : ''}" onclick="addToCart(${p.id})">
        <div>
          <div class="pos-prod-name">${p.name_am}</div>
          <div class="pos-prod-meta">${p.name_en}</div>
          <div class="pos-prod-meta" style="color:${isOutOfStock ? 'var(--danger)' : 'var(--text-muted)'}; font-weight:600;">${stockText}</div>
        </div>
        <div class="pos-prod-price">${formatMoney(p.selling_price)}</div>
      </div>
    `;
  });
  grid.innerHTML = html;
}

function addToCart(productId) {
  const prod = globalProducts.find((p) => p.id === productId);
  if (!prod) return;

  const existing = globalCart.find((it) => it.product.id === productId);
  if (existing) {
    existing.quantity += 1;
  } else {
    globalCart.push({ product: prod, quantity: 1 });
  }
  renderCart();
}

function changeCartQty(productId, delta) {
  const idx = globalCart.findIndex((it) => it.product.id === productId);
  if (idx < 0) return;

  globalCart[idx].quantity += delta;
  if (globalCart[idx].quantity <= 0) {
    globalCart.splice(idx, 1);
  }
  renderCart();
}

function clearCart() {
  globalCart = [];
  renderCart();
}

function renderCart() {
  const list = document.getElementById('cart-items-list');
  const countEl = document.getElementById('cart-items-count');
  countEl.textContent = globalCart.reduce((sum, it) => sum + it.quantity, 0);

  if (globalCart.length === 0) {
    list.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:16px; font-size:0.85rem;" data-i18n="emptyCart">ምንም የተመረጠ ዕቃ የለም</p>`;
    updateCartTotals();
    return;
  }

  let html = '';
  globalCart.forEach((it) => {
    const lineTotal = it.quantity * it.product.selling_price;
    html += `
      <div class="cart-item">
        <div class="cart-item-name">
          <div>${it.product.name_am}</div>
          <div style="font-size:0.75rem; color:var(--text-muted);">${formatMoney(it.product.selling_price)} × ${it.quantity}</div>
        </div>
        <div class="cart-qty-ctrl">
          <button class="btn-qty" onclick="changeCartQty(${it.product.id}, -1)">-</button>
          <span style="font-weight:700; min-width:20px; text-align:center;">${it.quantity}</span>
          <button class="btn-qty" onclick="changeCartQty(${it.product.id}, 1)">+</button>
        </div>
        <div style="font-weight:800; font-size:0.9rem; min-width:65px; text-align:right;">
          ${formatMoney(lineTotal)}
        </div>
      </div>
    `;
  });
  list.innerHTML = html;
  updateCartTotals();
}

function updateCartTotals() {
  const subtotal = globalCart.reduce((sum, it) => sum + (it.quantity * it.product.selling_price), 0);
  const discountInput = document.getElementById('cart-discount');
  const discount = Math.max(0, parseFloat(discountInput.value) || 0);
  const grandTotal = Math.max(0, subtotal - discount);

  document.getElementById('cart-subtotal').textContent = formatMoney(subtotal);
  document.getElementById('cart-grand-total').textContent = formatMoney(grandTotal);
}

function togglePosPaymentDetails() {
  const method = document.getElementById('pos-payment-method').value;
  const mixedBox = document.getElementById('pos-mixed-payment-box');
  const custBox = document.getElementById('pos-customer-box');
  mixedBox.style.display = method === 'mixed' ? 'block' : 'none';
  if (custBox) {
    custBox.style.display = method === 'credit' ? 'block' : 'none';
  }
}

async function submitSale() {
  if (globalCart.length === 0) {
    alert('እባክዎ ቢያንስ አንድ የሚሸጥ ዕቃ ይምረጡ');
    return;
  }

  const subtotal = globalCart.reduce((sum, it) => sum + (it.quantity * it.product.selling_price), 0);
  const discount = Math.max(0, parseFloat(document.getElementById('cart-discount').value) || 0);
  const grandTotal = Math.max(0, subtotal - discount);

  const method = document.getElementById('pos-payment-method').value;
  const customerId = document.getElementById('pos-customer-select').value;

  if (method === 'credit' && !customerId) {
    alert('በብድር ለመሸጥ እባክዎ ደንበኛ ይምረጡ');
    return;
  }

  // ክፍያዎችን ማዘጋጀት
  let payments = [];
  const cashAcc = globalAccounts.find((a) => a.code === 'cash') || { id: 1 };
  const teleAcc = globalAccounts.find((a) => a.code === 'telebirr') || { id: 3 };
  const cbeAcc  = globalAccounts.find((a) => a.code === 'cbe') || { id: 2 };

  if (method === 'cash') {
    payments.push({ account_id: cashAcc.id, amount: grandTotal });
  } else if (method === 'telebirr') {
    payments.push({ account_id: teleAcc.id, amount: grandTotal });
  } else if (method === 'cbe') {
    payments.push({ account_id: cbeAcc.id, amount: grandTotal });
  } else if (method === 'mixed') {
    const mCash = parseFloat(document.getElementById('pos-mixed-cash').value) || 0;
    const mTele = parseFloat(document.getElementById('pos-mixed-telebirr').value) || 0;
    const mCbe  = parseFloat(document.getElementById('pos-mixed-cbe').value) || 0;
    if (mCash > 0) payments.push({ account_id: cashAcc.id, amount: mCash });
    if (mTele > 0) payments.push({ account_id: teleAcc.id, amount: mTele });
    if (mCbe > 0) payments.push({ account_id: cbeAcc.id, amount: mCbe });
  }

  const items = globalCart.map((it) => ({
    product_id: it.product.id,
    quantity: it.quantity,
    unit_price: it.product.selling_price
  }));

  const payload = {
    uuid: 'sal-' + bin2hex(8),
    sale_date: new Date().toISOString().slice(0, 19).replace('T', ' '),
    customer_id: customerId ? parseInt(customerId) : null,
    items,
    discount,
    payments,
    payment_method: method
  };

  try {
    const res = await API.request('sales.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    if (res.success) {
      alert('✅ ሽያጩ በተሳካ ሁኔታ ተጠናቋል!');
      showReceipt(res.data, globalCart);
      clearCart();
      loadInitialData(); // ክምችቶችን ማደስ
      loadDashboard('today');
    } else {
      if (res.offline) {
        // ከመስመር ውጭ IndexedDB ውስጥ ክምችት መቀነስ፣ ደረሰኝ ማዘጋጀት እና ወረፋ መያዝ
        const offlineSale = await DB.applyOfflineSale(payload, globalFoodRecipes);
        API.updateStatusBadge();
        alert('🌐 ኢንተርኔት የለም፤ ሽያጩ ከመስመር ውጭ በተሳካ ሁኔታ ተመዝግቧል! ክምችት ቀንሷል። ኢንተርኔት ሲመጣ በራስ-ሰር ይመሳሰላል።');
        showReceipt(offlineSale, globalCart);
        clearCart();
        loadInitialData(); // በአካባቢው የተቀነሰውን ክምችት ማደስ
        loadDashboard('today'); // ዳሽቦርዱን ማደስ
      } else {
        alert(res.error || 'ሽያጩ አልተሳካም');
      }
    }
  } catch (err) {
    alert('የሽያጭ ስህተት: ' + err.message);
  }
}

function showReceipt(saleData, cartItems) {
  const box = document.getElementById('receipt-content-box');
  let itemsHtml = '';
  cartItems.forEach((it) => {
    itemsHtml += `
      <div style="display:flex; justify-content:space-between; margin:3px 0;">
        <span>${it.product.name_am} (${it.quantity} × ${formatMoney(it.product.selling_price)})</span>
        <span>${formatMoney(it.quantity * it.product.selling_price)}</span>
      </div>
    `;
  });

  box.innerHTML = `
    <div style="text-align:center; margin-bottom:10px;">
      <h3 style="margin:0; font-size:1.1rem;">ተክሉ ፉድ እና ቢዝነስ</h3>
      <p style="margin:2px 0; font-size:0.8rem; color:#64748b;">የሽያጭ ደረሰኝ</p>
      <p style="margin:0; font-size:0.75rem;">ደረሰኝ #: <strong>${saleData.receipt_number || 'REC-XXXX'}</strong></p>
      <p style="margin:0; font-size:0.75rem;">ቀን: ${new Date().toLocaleString('am-ET')}</p>
    </div>
    <hr style="border:none; border-top:1px dashed #cbd5e1; margin:8px 0;">
    <div>${itemsHtml}</div>
    <hr style="border:none; border-top:1px dashed #cbd5e1; margin:8px 0;">
    <div style="display:flex; justify-content:space-between; font-weight:700;">
      <span>ጠቅላላ ድምር:</span>
      <span>${formatMoney(saleData.grand_total)}</span>
    </div>
    <div style="display:flex; justify-content:space-between; font-size:0.85rem; color:#059669;">
      <span>የተከፈለ:</span>
      <span>${formatMoney(saleData.total_paid)}</span>
    </div>
    ${saleData.unpaid_balance > 0 ? `
      <div style="display:flex; justify-content:space-between; font-size:0.85rem; color:#ef4444; font-weight:700;">
        <span>የቀረ ዕዳ:</span>
        <span>${formatMoney(saleData.unpaid_balance)}</span>
      </div>
    ` : ''}
    <div style="text-align:center; margin-top:12px; font-size:0.8rem; color:#64748b;">
      እናመሰግናለን! እንደገና ይጎብኙን።
    </div>
  `;
  openModal('modal-receipt');
}

// =========================================================================
// 7. እቃ መረከቢያ (Goods Receiving Controller - Offline-First Cache & Sync)
// =========================================================================
async function loadReceivingData() {
  // 1. መጀመሪያ ከአካባቢው (IndexedDB) በማንበብ ወዲያውኑ UI ማሳየት
  const localList = await DB.getLocalPurchases();
  if (localList && localList.length > 0) {
    renderReceivingList(localList);
  }

  // 2. ኦንላይን ከሆንን ከሰርቨር API አዲሱን ዳታ ማምጣት እና ማደስ
  if (API.isOnline) {
    try {
      const res = await API.request('purchases.php?action=list');
      if (res.success) {
        const purchases = res.data.purchases || [];
        renderReceivingList(purchases);
        await DB.cachePurchases(purchases);
      }
    } catch (e) {
      console.error('Receiving list error:', e);
    }
  }
}

function renderReceivingList(purchases) {
  const container = document.getElementById('receiving-list-container');
  const countBadge = document.getElementById('receiving-count-badge');
  countBadge.textContent = `${purchases.length} ርክክቦች`;

  if (purchases.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:16px;">ምንም የተመዘገበ የእቃ ርክክብ የለም</p>`;
    return;
  }

  let html = '';
  purchases.forEach((p) => {
    let itemsText = p.items.map((it) => `${it.product_name_am} (${it.quantity} ${it.unit} × ${formatMoney(it.unit_price)})`).join(', ');
    const statusClass = p.payment_status === 'paid' ? 'paid' : (p.payment_status === 'partial' ? 'partial' : 'credit');
    const statusLabel = p.payment_status === 'paid' ? 'ሙሉ የተከፈለ' : (p.payment_status === 'partial' ? 'በከፊል' : 'በብድር / ያልተከፈለ');

    html += `
      <div class="trx-card">
        <div class="trx-row">
          <span class="trx-title">🏢 ${p.supplier_name || 'ተራ አቅራቢ'}</span>
          <span class="badge-tag ${statusClass}">${statusLabel}</span>
        </div>
        <div class="trx-items-summary">📦 ${itemsText || 'ዕቃዎች'}</div>
        <div class="trx-row">
          <span class="trx-date">📅 ${p.purchase_date}</span>
          <span class="trx-amount">${formatMoney(p.grand_total)}</span>
        </div>
        <div class="trx-row" style="font-size:0.75rem; color:var(--text-secondary); border-top:1px dashed var(--border-color); padding-top:4px;">
          <span>የተከፈለ፡ <strong>${formatMoney(p.paid_amount)}</strong></span>
          ${p.unpaid_balance > 0 ? `<span style="color:var(--danger); font-weight:700;">የቀረ ዕዳ፡ ${formatMoney(p.unpaid_balance)}</span>` : '<span style="color:var(--primary);">ዕዳ የለም</span>'}
        </div>
      </div>
    `;
  });
  container.innerHTML = html;
}

function addReceivingItemRow() {
  const container = document.getElementById('rec-items-container');
  const rowId = 'rec-row-' + Date.now();

  let prodOptions = '<option value="">-- ምርት ይምረጡ --</option>';
  globalProducts.forEach((p) => {
    prodOptions += `<option value="${p.id}" data-cost="${p.purchase_cost}" data-unit="${p.unit}">${p.name_am} (${p.name_en})</option>`;
  });

  const rowHtml = `
    <div id="${rowId}" style="display:flex; gap:6px; align-items:center; margin-bottom:8px; background:var(--bg-subtle); padding:6px; border-radius:var(--radius-sm);">
      <select class="form-control rec-item-prod" style="flex:2;" onchange="handleReceivingProductChange(this)" required>
        ${prodOptions}
      </select>
      <input type="number" class="form-control rec-item-qty" placeholder="መጠን" step="0.01" style="flex:1;" oninput="calculateReceivingTotals()" required>
      <input type="number" class="form-control rec-item-cost" placeholder="የአንዱ ዋጋ" step="0.01" style="flex:1;" oninput="calculateReceivingTotals()" required>
      <button type="button" class="btn btn-secondary btn-sm" style="color:var(--danger);" onclick="removeReceivingItemRow('${rowId}')">✕</button>
    </div>
  `;
  container.insertAdjacentHTML('beforeend', rowHtml);
}

function removeReceivingItemRow(rowId) {
  document.getElementById(rowId)?.remove();
  calculateReceivingTotals();
}

function handleReceivingProductChange(selectEl) {
  const selectedOpt = selectEl.options[selectEl.selectedIndex];
  const cost = selectedOpt.getAttribute('data-cost') || '0';
  const parent = selectEl.parentElement;
  const costInput = parent.querySelector('.rec-item-cost');
  if (costInput && (!costInput.value || parseFloat(costInput.value) === 0)) {
    costInput.value = cost;
  }
  calculateReceivingTotals();
}

function calculateReceivingTotals() {
  const rows = document.querySelectorAll('#rec-items-container > div');
  let grandTotal = 0;

  rows.forEach((row) => {
    const qty = parseFloat(row.querySelector('.rec-item-qty')?.value) || 0;
    const cost = parseFloat(row.querySelector('.rec-item-cost')?.value) || 0;
    grandTotal += (qty * cost);
  });

  document.getElementById('rec-grand-total-display').textContent = formatMoney(grandTotal);
  calculateReceivingDebt();
}

function calculateReceivingDebt() {
  const grandText = document.getElementById('rec-grand-total-display').textContent.replace(/[^\d.]/g, '');
  const grandTotal = parseFloat(grandText) || 0;
  const paidInput = document.getElementById('rec-paid-amount');
  const paid = Math.max(0, parseFloat(paidInput.value) || 0);

  const unpaidDebt = Math.max(0, grandTotal - paid);
  document.getElementById('rec-unpaid-debt-display').textContent = formatMoney(unpaidDebt);
}

function toggleNewSupplierInputs() {
  const val = document.getElementById('rec-supplier-select').value;
  const box = document.getElementById('new-supplier-box');
  box.style.display = val === 'NEW' ? 'block' : 'none';
}

async function handleReceiveGoods(e) {
  e.preventDefault();
  const supVal = document.getElementById('rec-supplier-select').value;
  let supplierId = supVal && supVal !== 'NEW' ? parseInt(supVal) : null;
  let newSupName = supVal === 'NEW' ? document.getElementById('rec-new-sup-name').value.trim() : null;
  let newSupPhone = supVal === 'NEW' ? document.getElementById('rec-new-sup-phone').value.trim() : null;
  let newSupAddress = supVal === 'NEW' ? document.getElementById('rec-new-sup-address').value.trim() : null;

  if (!supplierId && !newSupName) {
    alert('እባክዎ አቅራቢ ይምረጡ ወይም አዲስ አቅራቢ ስም ያስገቡ');
    return;
  }

  const rows = document.querySelectorAll('#rec-items-container > div');
  if (rows.length === 0) {
    alert('እባክዎ ቢያንስ አንድ የሚረከቡት ዕቃ ያስገቡ');
    return;
  }

  let items = [];
  rows.forEach((row) => {
    const prodId = parseInt(row.querySelector('.rec-item-prod')?.value) || 0;
    const qty = parseFloat(row.querySelector('.rec-item-qty')?.value) || 0;
    const cost = parseFloat(row.querySelector('.rec-item-cost')?.value) || 0;
    if (prodId > 0 && qty > 0) {
      items.push({ product_id: prodId, quantity: qty, unit_price: cost });
    }
  });

  if (items.length === 0) {
    alert('እባክዎ ትክክለኛ እቃዎችንና መጠናቸውን ያስገቡ');
    return;
  }

  const paidAmount = parseFloat(document.getElementById('rec-paid-amount').value) || 0;
  const paymentAccId = document.getElementById('rec-payment-account').value;
  const notes = document.getElementById('rec-notes').value.trim();

  if (paidAmount > 0 && !paymentAccId) {
    alert('የተከፈለ ገንዘብ ካለ የተከፈለበትን አካውንት ይምረጡ');
    return;
  }

  const payload = {
    uuid: 'pur-' + bin2hex(8),
    supplier_id: supplierId,
    new_supplier_name: newSupName,
    new_supplier_phone: newSupPhone,
    new_supplier_address: newSupAddress,
    purchase_date: new Date().toISOString().slice(0, 19).replace('T', ' '),
    paid_amount: paidAmount,
    payment_account_id: paymentAccId ? parseInt(paymentAccId) : null,
    notes,
    items
  };

  try {
    const res = await API.request('purchases.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    if (res.success) {
      alert(`✅ እቃው በተሳካ ሁኔታ ተረክቧል! ክምችት ጨምሯል።\nየአቅራቢው አሁን ያለ ጠቅላላ ዕዳ: ${formatMoney(res.data.supplier_total_debt)}`);
      closeModal('modal-receive-goods');
      document.getElementById('rec-items-container').innerHTML = '';
      loadReceivingData();
      loadInitialData(); // ክምችቶችን ማደስ
      loadDashboard('today');
    } else {
      if (res.offline) {
        // ከመስመር ውጭ እቃዎችን በክምችት መደመር፣ የአቅራቢ ዕዳ ማስላት እና ወረፋ መያዝ
        const offlineRec = await DB.applyOfflineReceiving(payload);
        API.updateStatusBadge();
        alert('🌐 ኢንተርኔት የለም፤ እቃ ርክክቡ ከመስመር ውጭ በተሳካ ሁኔታ ተመዝግቧል! ክምችት ጨምሯል፤ የአቅራቢ ዕዳ ታድሷል። ኢንተርኔት ሲመጣ በራስ-ሰር ይመሳሰላል።');
        closeModal('modal-receive-goods');
        document.getElementById('rec-items-container').innerHTML = '';
        loadReceivingData(); // በአካባቢው የተመዘገበውን ርክክብ ማሳየት
        loadInitialData(); // በአካባቢው የጨመረውን ክምችት ማደስ
        loadDashboard('today'); // ዳሽቦርድ ማደስ
      } else {
        alert(res.error || 'እቃውን መረከብ አልተሳካም');
      }
    }
  } catch (err) {
    alert('የርክክብ ስህተት: ' + err.message);
  }
}

// =========================================================================
// 8. የአቅራቢ ሂሳብ ዝርዝር እና ታሪክ (Supplier Statement & Ledger - Offline-First Cache & Sync)
// =========================================================================
async function viewSupplierStatement(supplierId) {
  // 1. መጀመሪያ ከአካባቢው (IndexedDB) በማዘጋጀት ወዲያውኑ ማሳየት
  const [localPurchases, localContacts] = await Promise.all([
    DB.getLocalPurchases(),
    DB.getLocalContacts('supplier')
  ]);

  const sup = (localContacts || []).find((c) => parseInt(c.id) === parseInt(supplierId));
  if (sup) {
    const supPurchases = (localPurchases || []).filter((p) => parseInt(p.supplier_id) === parseInt(supplierId));
    let totalPurchases = 0;
    let totalPaid = 0;
    let totalUnits = 0;

    supPurchases.forEach((p) => {
      totalPurchases += parseFloat(p.grand_total || 0);
      totalPaid += parseFloat(p.paid_amount || 0);
      if (p.items) {
        p.items.forEach((it) => { totalUnits += parseFloat(it.quantity || 0); });
      }
    });

    const localData = {
      supplier: {
        id: sup.id,
        name: sup.name,
        phone: sup.phone,
        address: sup.address,
        outstanding_balance: parseFloat(sup.current_balance || (totalPurchases - totalPaid))
      },
      summary: {
        total_goods_received_units: totalUnits,
        total_purchase_amount: totalPurchases,
        total_payments_made: totalPaid,
        outstanding_balance: parseFloat(sup.current_balance || (totalPurchases - totalPaid))
      },
      purchases: supPurchases,
      payments: []
    };

    currentSupplierStatementData = localData;
    renderSupplierStatement(localData);
    switchPage('page-supplier-details');
  }

  // 2. ኦንላይን ከሆንን ከሰርቨር API አዲሱን ዳታ ማምጣት እና ማደስ
  if (API.isOnline) {
    try {
      const res = await API.request(`contacts.php?action=supplier_statement&supplier_id=${supplierId}`);
      if (res.success) {
        currentSupplierStatementData = res.data;
        renderSupplierStatement(res.data);
      }
    } catch (err) {
      console.error('Supplier statement online error:', err);
    }
  }
}

function renderSupplierStatement(data) {
  const sup = data.supplier;
  const sm = data.summary;

  document.getElementById('stmt-supplier-name').textContent = sup.name;
  document.getElementById('stmt-supplier-phone').textContent = `ስልክ፡ ${sup.phone || '-'}`;
  document.getElementById('stmt-supplier-address').textContent = `አድራሻ፡ ${sup.address || '-'}`;

  document.getElementById('stmt-goods-units').textContent = sm.total_goods_received_units;
  document.getElementById('stmt-total-purchase').textContent = formatMoney(sm.total_purchase_amount);
  document.getElementById('stmt-total-paid').textContent = formatMoney(sm.total_payments_made);
  document.getElementById('stmt-outstanding-balance').textContent = formatMoney(sm.outstanding_balance);

  // ርክክቦችን ማሳየት
  renderStatementPurchases(data.purchases);
  // ክፍያዎችን ማሳየት
  renderStatementPayments(data.payments);
}

function renderStatementPurchases(purchases) {
  const container = document.getElementById('stmt-purchases-container');
  if (!purchases || purchases.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:12px;">ምንም የእቃ ርክክብ የለም</p>`;
    return;
  }

  let html = '';
  purchases.forEach((p) => {
    let itemsText = p.items.map((it) => `${it.product_name_am} (${it.quantity} × ${formatMoney(it.unit_price)})`).join(', ');
    const statusClass = p.payment_status === 'paid' ? 'paid' : (p.payment_status === 'partial' ? 'partial' : 'credit');
    const statusLabel = p.payment_status === 'paid' ? 'ሙሉ የተከፈለ' : (p.payment_status === 'partial' ? 'በከፊል' : 'በብድር / ያልተከፈለ');

    html += `
      <div class="trx-card">
        <div class="trx-row">
          <span class="trx-title">ርክክብ #${p.id}</span>
          <span class="badge-tag ${statusClass}">${statusLabel}</span>
        </div>
        <div class="trx-items-summary">📦 ${itemsText}</div>
        <div class="trx-row">
          <span class="trx-date">📅 ${p.purchase_date}</span>
          <span class="trx-amount">${formatMoney(p.grand_total)}</span>
        </div>
        <div class="trx-row" style="font-size:0.75rem; border-top:1px dashed var(--border-color); padding-top:4px;">
          <span>የተከፈለ፡ ${formatMoney(p.paid_amount)}</span>
          <span style="color:var(--danger); font-weight:700;">የቀረ ዕዳ፡ ${formatMoney(p.unpaid_balance)}</span>
        </div>
      </div>
    `;
  });
  container.innerHTML = html;
}

function renderStatementPayments(payments) {
  const container = document.getElementById('stmt-payments-container');
  if (!payments || payments.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:12px;">ምንም የተመዘገበ ክፍያ የለም</p>`;
    return;
  }

  let html = '';
  payments.forEach((py) => {
    html += `
      <div class="trx-card" style="border-left:3px solid var(--primary);">
        <div class="trx-row">
          <span class="trx-title">💳 የተከፈለ ክፍያ</span>
          <span class="badge-tag paid">ተከፍሏል</span>
        </div>
        <div class="trx-row">
          <span style="font-size:0.8rem; color:var(--text-secondary);">${py.account_name_am || 'ባንክ/ጥሬ ገንዘብ'} - ${py.notes || ''}</span>
          <span style="font-weight:800; color:var(--primary); font-size:0.95rem;">${formatMoney(py.amount)}</span>
        </div>
        <div class="trx-row">
          <span class="trx-date">📅 ${py.payment_date}</span>
          <span style="font-size:0.72rem; color:var(--text-muted);">ፈጻሚ: ${py.paid_by_name || 'Admin'}</span>
        </div>
      </div>
    `;
  });
  container.innerHTML = html;
}

function filterSupplierTx(status, btnEl) {
  btnEl.parentElement.querySelectorAll('.btn').forEach((b) => b.classList.remove('active'));
  btnEl.classList.add('active');

  if (!currentSupplierStatementData) return;
  let allP = currentSupplierStatementData.purchases;

  if (status === 'paid') allP = allP.filter((p) => p.payment_status === 'paid');
  else if (status === 'partial') allP = allP.filter((p) => p.payment_status === 'partial');
  else if (status === 'unpaid') allP = allP.filter((p) => p.payment_status === 'credit' || p.unpaid_balance > 0);

  renderStatementPurchases(allP);
}

function openSupplierPaymentModal() {
  if (!currentSupplierStatementData) return;
  const sup = currentSupplierStatementData.supplier;
  document.getElementById('sp-supplier-id').value = sup.id;
  document.getElementById('sp-supplier-name').value = sup.name;
  document.getElementById('sp-current-debt').value = formatMoney(sup.outstanding_balance);
  document.getElementById('sp-amount').value = '';
  document.getElementById('sp-new-debt').value = formatMoney(sup.outstanding_balance);
  document.getElementById('sp-notes').value = '';
  openModal('modal-supplier-payment');
}

function calculateRemainingSupplierDebt() {
  if (!currentSupplierStatementData) return;
  const currentDebt = currentSupplierStatementData.supplier.outstanding_balance;
  const amt = parseFloat(document.getElementById('sp-amount').value) || 0;
  const newDebt = Math.max(0, currentDebt - amt);
  document.getElementById('sp-new-debt').value = formatMoney(newDebt);
}

async function handleSupplierPayment(e) {
  e.preventDefault();
  const supplierId = parseInt(document.getElementById('sp-supplier-id').value);
  const accountId = parseInt(document.getElementById('sp-account-id').value);
  const amount = parseFloat(document.getElementById('sp-amount').value) || 0;
  const notes = document.getElementById('sp-notes').value.trim();

  if (amount <= 0 || !accountId) {
    alert('እባክዎ ትክክለኛ የክፍያ መጠን እና አካውንት ይምረጡ');
    return;
  }

  const payload = {
    supplier_id: supplierId,
    account_id: accountId,
    amount,
    payment_date: new Date().toISOString().slice(0, 19).replace('T', ' '),
    notes
  };

  try {
    const res = await API.request('contacts.php?action=make_payment', {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    if (res.success) {
      alert(`✅ ክፍያው በተሳካ ሁኔታ ተፈጽሟል!\nየአቅራቢው አዲሱ ቀሪ ዕዳ: ${formatMoney(res.data.new_balance)}`);
      closeModal('modal-supplier-payment');
      viewSupplierStatement(supplierId); // ሂሳቡን ማደስ
      loadDashboard('today');
    } else {
      if (res.offline) {
        payload.uuid = 'sup-pay-' + supplierId + '-' + bin2hex(6);
        await DB.applyOfflineSupplierPayment({
          ...payload,
          contact_id: supplierId
        });
        API.updateStatusBadge();
        alert('🌐 ኢንተርኔት የለም፤ ክፍያው ከመስመር ውጭ በተሳካ ሁኔታ ተመዝግቧል! የአቅራቢው ዕዳ እና የሂሳብ ሚዛን ቀንሷል። ኢንተርኔት ሲመጣ በራስ-ሰር ይመሳሰላል።');
        closeModal('modal-supplier-payment');
        viewSupplierStatement(supplierId); // በአካባቢው የዘመነውን ሂሳብ ማሳየት
        loadDashboard('today');
      } else {
        alert(res.error || 'ክፍያውን መመዝገብ አልተሳካም');
      }
    }
  } catch (err) {
    alert('የክፍያ ስህተት: ' + err.message);
  }
}

// =========================================================================
// 9. የምግብ ፈጠራ እና ሬሲፒ (Food Creation & Recipes - Offline-First Cache & Sync)
// =========================================================================
async function loadFoodRecipes() {
  // 1. መጀመሪያ ከአካባቢው (IndexedDB) በማንበብ ወዲያውኑ UI ማሳየት
  const localRecs = await DB.getLocalRecipes();
  if (localRecs && localRecs.length > 0) {
    globalFoodRecipes = localRecs;
    renderFoodRecipes(globalFoodRecipes);
  }

  // 2. ኦንላይን ከሆንን ከሰርቨር API አዲሱን ዳታ ማምጣት እና ማደስ
  if (API.isOnline) {
    try {
      const res = await API.request('recipes.php?action=list');
      if (res.success) {
        globalFoodRecipes = res.data.foods || [];
        renderFoodRecipes(globalFoodRecipes);
        await DB.cacheRecipes(globalFoodRecipes);
      }
    } catch (e) {
      console.error('Recipes load error:', e);
    }
  }
}

function renderFoodRecipes(foods) {
  const container = document.getElementById('food-recipes-cards-container');
  const countBadge = document.getElementById('recipes-count-badge');
  countBadge.textContent = `${foods.length} ምግቦች`;

  if (foods.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:16px;">ምንም የተመዘገበ ምግብ ወይም ሬሲፒ የለም</p>`;
    return;
  }

  let html = '';
  foods.forEach((f) => {
    let ingBadges = f.ingredients.map((ing) => `
      <span style="background:var(--bg-subtle); border:1px solid var(--border-color); padding:2px 6px; border-radius:4px; font-size:0.75rem; white-space:nowrap;">
        ${ing.ingredient_name_am}: <strong>${ing.quantity_needed} ${ing.unit}</strong>
      </span>
    `).join(' ');

    const servingsColor = f.max_servings > 5 ? 'var(--primary)' : (f.max_servings > 0 ? 'var(--warning)' : 'var(--danger)');

    html += `
      <div class="trx-card" style="margin-bottom:12px; padding:12px;">
        <div class="trx-row">
          <div>
            <div style="font-size:1.05rem; font-weight:800; color:var(--text-main);">${f.name_am}</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">${f.name_en}</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:1.1rem; font-weight:800; color:var(--primary);">${formatMoney(f.selling_price)}</div>
            <div style="font-size:0.7rem; color:var(--text-secondary);">የመሸጫ ዋጋ</div>
          </div>
        </div>

        <div style="margin:8px 0; display:flex; flex-wrap:wrap; gap:4px;">
          ${ingBadges || '<span style="color:var(--text-muted); font-size:0.75rem;">ግብዓቶች አልተያያዙም</span>'}
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px; background:var(--bg-subtle); padding:8px; border-radius:var(--radius-sm); font-size:0.75rem; text-align:center;">
          <div>
            <div style="color:var(--text-muted);">የአንድ ወጪ (COGS)</div>
            <div style="font-weight:700; color:var(--text-main);">${formatMoney(f.cost_per_serving)}</div>
          </div>
          <div>
            <div style="color:var(--text-muted);">የሚጠበቅ ትርፍ</div>
            <div style="font-weight:700; color:var(--primary);">${formatMoney(f.estimated_profit)}</div>
          </div>
          <div>
            <div style="color:var(--text-muted);">ሊዘጋጅ የሚችል</div>
            <div style="font-weight:800; color:${servingsColor};">${f.max_servings} ሰርቪንግ</div>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:6px; margin-top:8px;">
          <button class="btn btn-secondary btn-sm" onclick="editFoodRecipe(${f.id})">✏️ አድስ</button>
          <button class="btn btn-secondary btn-sm" style="color:var(--danger);" onclick="deleteFoodRecipe(${f.id})">🗑️ ሰርዝ</button>
        </div>
      </div>
    `;
  });
  container.innerHTML = html;
}

function addRecipeIngredientRow(selectedIngId = '', qty = '') {
  const container = document.getElementById('recipe-ingredients-container');
  const rowId = 'rcp-ing-row-' + Date.now() + Math.random().toString(36).substring(2, 5);

  let ingOptions = '<option value="">-- ጥሬ እቃ ይምረጡ --</option>';
  globalProducts.forEach((p) => {
    const isSel = p.id === parseInt(selectedIngId) ? 'selected' : '';
    ingOptions += `<option value="${p.id}" data-unit="${p.unit}" data-cost="${p.purchase_cost}" ${isSel}>${p.name_am} - ${p.unit}</option>`;
  });

  const rowHtml = `
    <div id="${rowId}" style="display:flex; gap:6px; align-items:center; margin-bottom:8px; background:var(--bg-subtle); padding:6px; border-radius:var(--radius-sm);">
      <select class="form-control rcp-ing-select" style="flex:2;" onchange="calculateRecipeProfit()" required>
        ${ingOptions}
      </select>
      <input type="number" class="form-control rcp-ing-qty" placeholder="መጠን" step="0.001" style="flex:1;" value="${qty}" oninput="calculateRecipeProfit()" required>
      <button type="button" class="btn btn-secondary btn-sm" style="color:var(--danger);" onclick="document.getElementById('${rowId}').remove(); calculateRecipeProfit();">✕</button>
    </div>
  `;
  container.insertAdjacentHTML('beforeend', rowHtml);
}

function calculateRecipeProfit() {
  const rows = document.querySelectorAll('#recipe-ingredients-container > div');
  let totalCost = 0;

  rows.forEach((row) => {
    const select = row.querySelector('.rcp-ing-select');
    const qty = parseFloat(row.querySelector('.rcp-ing-qty')?.value) || 0;
    if (select && select.selectedIndex > 0) {
      const opt = select.options[select.selectedIndex];
      const cost = parseFloat(opt.getAttribute('data-cost')) || 0;
      totalCost += (qty * cost);
    }
  });

  const sellingPrice = parseFloat(document.getElementById('rcp-selling-price')?.value) || 0;
  const profit = Math.max(0, sellingPrice - totalCost);

  document.getElementById('rcp-cost-per-serving').textContent = formatMoney(totalCost);
  document.getElementById('rcp-estimated-profit').textContent = formatMoney(profit);
}

async function handleSaveFoodRecipe(e) {
  e.preventDefault();
  const foodId = document.getElementById('recipe-food-id').value;
  const nameAm = document.getElementById('rcp-food-name-am').value.trim();
  const nameEnInput = document.getElementById('rcp-food-name-en');
  const nameEn = (nameEnInput && nameEnInput.value.trim()) ? nameEnInput.value.trim() : nameAm;
  const sellingPrice = parseFloat(document.getElementById('rcp-selling-price').value) || 0;

  const rows = document.querySelectorAll('#recipe-ingredients-container > div');
  if (rows.length === 0) {
    alert('እባክዎ ቢያንስ አንድ ጥሬ እቃ ይምረጡ');
    return;
  }

  let ingredients = [];
  rows.forEach((row) => {
    const select = row.querySelector('.rcp-ing-select');
    const qty = parseFloat(row.querySelector('.rcp-ing-qty')?.value) || 0;
    if (select && select.selectedIndex > 0 && qty > 0) {
      const opt = select.options[select.selectedIndex];
      ingredients.push({
        ingredient_product_id: parseInt(select.value),
        quantity_needed: qty,
        unit: opt.getAttribute('data-unit') || 'kg'
      });
    }
  });

  if (ingredients.length === 0) {
    alert('እባክዎ ትክክለኛ ግብዓቶችንና መጠናቸውን ያስገቡ');
    return;
  }

  const payload = {
    food_id: foodId ? parseInt(foodId) : null,
    name_am: nameAm,
    name_en: nameEn,
    selling_price: sellingPrice,
    unit: 'plate',
    category_id: 4, // የተዘጋጁ ምግቦች
    ingredients
  };

  try {
    const res = await API.request('recipes.php?action=save_recipe', {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    if (res.success) {
      alert('✅ የምግብ አዘገጃጀቱ (Recipe) በተሳካ ሁኔታ ተቀምጧል!');
      closeModal('modal-create-food-recipe');
      loadFoodRecipes();
      loadInitialData();
    } else {
      alert(res.error || 'ማስቀመጥ አልተሳካም');
    }
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

function editFoodRecipe(foodId) {
  const food = globalFoodRecipes.find((f) => f.id === foodId);
  if (!food) return;

  document.getElementById('recipe-food-id').value = food.id;
  document.getElementById('rcp-food-name-am').value = food.name_am;
  document.getElementById('rcp-food-name-en').value = food.name_en;
  document.getElementById('rcp-selling-price').value = food.selling_price;

  const container = document.getElementById('recipe-ingredients-container');
  container.innerHTML = '';
  food.ingredients.forEach((ing) => {
    addRecipeIngredientRow(ing.ingredient_product_id, ing.quantity_needed);
  });
  calculateRecipeProfit();
  openModal('modal-create-food-recipe');
}

async function deleteFoodRecipe(foodId) {
  if (!confirm('ይህንን የምግብ ሬሲፒ መሰረዝ ይፈልጋሉ?')) return;
  try {
    const res = await API.request('recipes.php?action=delete', {
      method: 'POST',
      body: JSON.stringify({ food_id: foodId })
    });
    if (res.success) {
      alert('ሬሲፒው ተሰርዟል');
      loadFoodRecipes();
    } else {
      alert(res.error || 'መሰረዝ አልተሳካም');
    }
  } catch (e) {
    alert('ስህተት: ' + e.message);
  }
}

// =========================================================================
// 10. ክምችት እና ምርቶች (Inventory Controller)
// =========================================================================
function renderStockTable() {
  const container = document.getElementById('stock-cards-container');
  const countBadge = document.getElementById('products-count-badge');
  countBadge.textContent = `${globalProducts.length} ምርቶች`;

  const search = (document.getElementById('stock-search-input')?.value || '').toLowerCase().trim();

  let filtered = globalProducts.filter((p) => {
    return p.name_am.toLowerCase().includes(search) || p.name_en.toLowerCase().includes(search) || (p.category_name_am || '').toLowerCase().includes(search);
  });

  if (filtered.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:16px;">ምንም የተመዘገበ ምርት የለም</p>`;
    return;
  }

  let html = '';
  filtered.forEach((p) => {
    const isLow = p.is_low_stock;
    const catLabel = p.category_name_am || 'ምድብ';

    html += `
      <div class="trx-card" style="${isLow ? 'border-left:3px solid var(--danger);' : ''}">
        <div class="trx-row">
          <div>
            <span class="trx-title">${p.name_am}</span>
            <span style="font-size:0.75rem; color:var(--text-muted);"> (${p.name_en})</span>
          </div>
          <span class="badge-tag neutral">${catLabel}</span>
        </div>
        <div class="trx-row">
          <span style="font-size:0.8rem; color:var(--text-secondary);">ግዢ፡ ${formatMoney(p.purchase_cost)} | መሸጫ፡ ${formatMoney(p.selling_price)}</span>
          <span style="font-weight:800; font-size:0.95rem; color:${isLow ? 'var(--danger)' : 'var(--text-main)'};">
            ${p.total_stock} ${p.unit}
          </span>
        </div>
        ${isLow ? `
          <div style="font-size:0.72rem; color:var(--danger); font-weight:700;">
            ⚠️ ዝቅተኛ ክምችት ማስጠንቀቂያ! (ዝቅተኛ ገደብ: ${p.min_stock_level} ${p.unit})
          </div>
        ` : ''}
      </div>
    `;
  });
  container.innerHTML = html;
}

async function handleCreateProduct(e) {
  e.preventDefault();
  const nameAm = document.getElementById('np-name-am').value.trim();
  const nameEnInput = document.getElementById('np-name-en');
  const nameEn = (nameEnInput && nameEnInput.value.trim()) ? nameEnInput.value.trim() : nameAm;
  const categoryId = parseInt(document.getElementById('np-category').value);
  const unit = document.getElementById('np-unit').value;
  const pCost = parseFloat(document.getElementById('np-purchase-cost')?.value) || 0;
  const sPriceInput = document.getElementById('np-selling-price');
  const sPrice = sPriceInput ? (parseFloat(sPriceInput.value) || 0) : 0;
  const opStock = parseFloat(document.getElementById('np-opening-stock')?.value) || 0;
  const minStock = parseFloat(document.getElementById('np-min-stock')?.value) || 0;

  const payload = {
    name_am: nameAm,
    name_en: nameEn,
    category_id: categoryId,
    unit: unit,
    purchase_cost: pCost,
    selling_price: sPrice,
    opening_stock: opStock,
    min_stock_level: minStock
  };

  try {
    const res = await API.request('products.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('✅ ምርቱ በተሳካ ሁኔታ ተመዝግቧል!');
      closeModal('modal-new-product');
      loadInitialData();
    } else {
      alert(res.error || 'ምርቱን መመዝገብ አልተሳካም');
    }
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

async function handleAdjustStock(e) {
  e.preventDefault();
  const prodId = parseInt(document.getElementById('adj-product').value);
  const adjType = document.getElementById('adj-type').value;
  const qty = parseFloat(document.getElementById('adj-qty').value) || 0;
  const reason = document.getElementById('adj-reason').value.trim();

  if (!prodId || qty <= 0 || !reason) {
    alert('እባክዎ ሁሉንም መስኮች በትክክል ያስገቡ');
    return;
  }

  try {
    const res = await API.request('products.php?action=adjust_stock', {
      method: 'POST',
      body: JSON.stringify({ product_id: prodId, adjustment_type: adjType, quantity: qty, reason })
    });
    if (res.success) {
      alert('✅ የክምችት ማስተካከያው ተመዝግቧል!');
      closeModal('modal-adjust-stock');
      loadInitialData();
    } else {
      alert(res.error || 'ማስተካከያው አልተሳካም');
    }
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

// =========================================================================
// 11. ገንዘብ መውጣት (Withdrawals Controller - Offline-First Cache & Sync)
// =========================================================================
async function loadWithdrawals() {
  const search = (document.getElementById('wth-search-input')?.value || '').trim();

  // 1. መጀመሪያ ከአካባቢው (IndexedDB) በማንበብ ወዲያውኑ UI ማሳየት
  const localList = await DB.getLocalWithdrawals();
  if (localList && localList.length > 0) {
    const filtered = search ? localList.filter((w) => (w.recipient_name || '').toLowerCase().includes(search.toLowerCase())) : localList;
    renderWithdrawalsList(filtered);
    const sum = filtered.reduce((acc, it) => acc + parseFloat(it.amount || 0), 0);
    document.getElementById('withdrawals-total-badge').textContent = formatMoney(sum);
  }

  // 2. ኦንላይን ከሆንን ከሰርቨር API አዲሱን ዳታ ማምጣት እና ማደስ
  if (API.isOnline) {
    try {
      const res = await API.request(`withdrawals.php?action=list&recipient=${encodeURIComponent(search)}`);
      if (res.success) {
        const list = res.data.withdrawals || [];
        renderWithdrawalsList(list);
        document.getElementById('withdrawals-total-badge').textContent = formatMoney(res.data.total_amount);
        await DB.cacheWithdrawals(list);
      }
    } catch (e) {
      console.error('Withdrawals error:', e);
    }
  }
}

function renderWithdrawalsList(withdrawals) {
  const container = document.getElementById('withdrawals-list-container');
  if (withdrawals.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:16px;">ምንም የወጣ ገንዘብ አልተመዘገበም</p>`;
    return;
  }

  let html = '';
  withdrawals.forEach((w) => {
    html += `
      <div class="trx-card" style="border-left:3px solid var(--warning);">
        <div class="trx-row">
          <span class="trx-title">👤 ተቀባይ፡ ${w.recipient_name}</span>
          <span class="trx-amount" style="color:var(--warning);">${formatMoney(w.amount)}</span>
        </div>
        <div class="trx-row">
          <span style="font-size:0.8rem; color:var(--text-secondary);">ምክንያት፡ ${w.reason}</span>
          <span class="badge-tag neutral">${w.account_name_am || 'አካውንት'}</span>
        </div>
        <div class="trx-row">
          <span class="trx-date">📅 ${w.withdrawal_date}</span>
          <span style="font-size:0.72rem; color:var(--text-muted);">መዝጋቢ: ${w.created_by_name || 'Admin'}</span>
        </div>
      </div>
    `;
  });
  container.innerHTML = html;
}

async function handleCreateWithdrawal(e) {
  e.preventDefault();
  const accId = parseInt(document.getElementById('wth-account').value);
  const amt = parseFloat(document.getElementById('wth-amount').value) || 0;
  const rec = document.getElementById('wth-recipient').value.trim();
  const reason = document.getElementById('wth-reason').value.trim();
  const notes = document.getElementById('wth-notes').value.trim();

  if (!accId || amt <= 0 || !rec || !reason) {
    alert('እባክዎ ሁሉንም መስኮች በትክክል ያስገቡ');
    return;
  }

  const payload = {
    uuid: 'wth-' + bin2hex(8),
    account_id: accId,
    amount: amt,
    recipient_name: rec,
    reason: reason,
    notes: notes,
    withdrawal_date: new Date().toISOString().slice(0, 19).replace('T', ' ')
  };

  try {
    const res = await API.request('withdrawals.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('✅ ገንዘብ መውጣቱ ተመዝግቧል፤ የአካውንቱ ሂሳብ ቀንሷል');
      closeModal('modal-new-withdrawal');
      loadWithdrawals();
      loadDashboard('today');
    } else {
      if (res.offline) {
        const accObj = (globalAccounts || []).find((a) => parseInt(a.id) === accId);
        await DB.applyOfflineWithdrawal({
          ...payload,
          account_name: accObj ? (accObj.name_am || accObj.name_en) : 'ጥሬ ገንዘብ'
        });
        API.updateStatusBadge();
        alert('🌐 ኢንተርኔት የለም፤ ገንዘብ መውጣቱ ከመስመር ውጭ በተሳካ ሁኔታ ተመዝግቧል! የአካውንቱ ሂሳብ ቀንሷል። ኢንተርኔት ሲመጣ በራስ-ሰር ይመሳሰላል።');
        closeModal('modal-new-withdrawal');
        loadWithdrawals(); // በአካባቢው የተመዘገበውን ማሳየት
        loadDashboard('today'); // ዳሽቦርድ ማደስ
      } else {
        alert(res.error || 'መመዝገብ አልተሳካም');
      }
    }
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

// =========================================================================
// 12. የስራ ማስኬጃ ወጪዎች (Expenses Controller - Offline-First Cache & Sync)
// =========================================================================
async function loadExpenses() {
  const search = (document.getElementById('exp-search-input')?.value || '').trim();

  // 1. መጀመሪያ ከአካባቢው (IndexedDB) በማንበብ ወዲያውኑ UI ማሳየት
  const localList = await DB.getLocalExpenses();
  if (localList && localList.length > 0) {
    const filtered = search ? localList.filter((ex) => (ex.description || '').toLowerCase().includes(search.toLowerCase())) : localList;
    renderExpensesList(filtered);
    const sum = filtered.reduce((acc, it) => acc + parseFloat(it.amount || 0), 0);
    document.getElementById('expenses-total-badge').textContent = formatMoney(sum);
  }

  // 2. ኦንላይን ከሆንን ከሰርቨር API አዲሱን ዳታ ማምጣት እና ማደስ
  if (API.isOnline) {
    try {
      const res = await API.request(`expenses.php?action=list&search=${encodeURIComponent(search)}`);
      if (res.success) {
        const list = res.data.expenses || [];
        renderExpensesList(list);
        document.getElementById('expenses-total-badge').textContent = formatMoney(res.data.total_expenses);
        await DB.cacheExpenses(list);
      }
    } catch (e) {
      console.error('Expenses error:', e);
    }
  }
}

function renderExpensesList(expenses) {
  const container = document.getElementById('expenses-list-container');
  if (expenses.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:16px;">ምንም የስራ ማስኬጃ ወጪ የለም</p>`;
    return;
  }

  let html = '';
  expenses.forEach((ex) => {
    html += `
      <div class="trx-card" style="border-left:3px solid var(--danger);">
        <div class="trx-row">
          <span class="trx-title">${ex.category_name_am || 'ወጪ'}</span>
          <span class="trx-amount" style="color:var(--danger);">${formatMoney(ex.amount)}</span>
        </div>
        <div class="trx-row">
          <span style="font-size:0.8rem; color:var(--text-secondary);">${ex.description}</span>
          <span class="badge-tag neutral">${ex.account_name_am || 'አካውንት'}</span>
        </div>
        <div class="trx-row">
          <span class="trx-date">📅 ${ex.expense_date}</span>
          <span style="font-size:0.72rem; color:var(--text-muted);">መዝጋቢ: ${ex.created_by_name || 'Admin'}</span>
        </div>
      </div>
    `;
  });
  container.innerHTML = html;
}

async function handleCreateExpense(e) {
  e.preventDefault();
  const catId = parseInt(document.getElementById('exp-category').value);
  const accId = parseInt(document.getElementById('exp-account').value);
  const amt = parseFloat(document.getElementById('exp-amount').value) || 0;
  const desc = document.getElementById('exp-desc').value.trim();

  if (!catId || !accId || amt <= 0 || !desc) {
    alert('እባክዎ ሁሉንም መስኮች በትክክል ያስገቡ');
    return;
  }

  const payload = {
    uuid: 'exp-' + bin2hex(8),
    category_id: catId,
    account_id: accId,
    amount: amt,
    description: desc,
    expense_date: new Date().toISOString().slice(0, 19).replace('T', ' ')
  };

  try {
    const res = await API.request('expenses.php?action=create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.success) {
      alert('✅ የስራ ማስኬጃ ወጪው ተመዝግቧል');
      closeModal('modal-new-expense');
      loadExpenses();
      loadDashboard('today');
    } else {
      if (res.offline) {
        const catSelect = document.getElementById('exp-category');
        const catText = catSelect.options[catSelect.selectedIndex]?.text || 'የስራ ማስኬጃ';
        const accObj = (globalAccounts || []).find((a) => parseInt(a.id) === accId);
        await DB.applyOfflineExpense({
          ...payload,
          category_name: catText,
          account_name: accObj ? (accObj.name_am || accObj.name_en) : 'ጥሬ ገንዘብ'
        });
        API.updateStatusBadge();
        alert('🌐 ኢንተርኔት የለም፤ ወጪው ከመስመር ውጭ በተሳካ ሁኔታ ተመዝግቧል! የአካውንቱ ሂሳብ ቀንሷል። ኢንተርኔት ሲመጣ በራስ-ሰር ይመሳሰላል።');
        closeModal('modal-new-expense');
        loadExpenses(); // በአካባቢው የተመዘገበውን ማሳየት
        loadDashboard('today'); // ዳሽቦርድ ማደስ
      } else {
        alert(res.error || 'መመዝገብ አልተሳካም');
      }
    }
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

// =========================================================================
// 13. አቅራቢዎች እና ደንበኞች (Suppliers & Customers - Offline-First Cache & Sync)
// =========================================================================
async function loadSuppliers() {
  // 1. መጀመሪያ ከአካባቢው (IndexedDB) በማንበብ ወዲያውኑ UI ማሳየት
  const localSups = await DB.getLocalContacts('supplier');
  if (localSups && localSups.length > 0) {
    globalSuppliers = localSups;
    renderSuppliersCards(globalSuppliers);
    populateSupplierDropdowns();
  }

  // 2. ኦንላይን ከሆንን ከሰርቨር API አዲሱን ዳታ ማምጣት እና ማደስ
  if (API.isOnline) {
    try {
      const res = await API.request('contacts.php?action=list&type=supplier');
      if (res.success) {
        globalSuppliers = res.data.contacts || [];
        renderSuppliersCards(globalSuppliers);
        populateSupplierDropdowns();
        await DB.cacheContacts(globalSuppliers);
      }
    } catch (e) {
      console.error('Suppliers error:', e);
    }
  }
}

function renderSuppliersCards(suppliers) {
  const container = document.getElementById('suppliers-cards-container');
  const countBadge = document.getElementById('suppliers-count-badge');
  countBadge.textContent = `${suppliers.length} አቅራቢዎች`;

  if (suppliers.length === 0) {
    container.innerHTML = `<p style="color:var(--text-muted); text-align:center; padding:16px;">ምንም የተመዘገበ አቅራቢ የለም</p>`;
    return;
  }

  let html = '';
  suppliers.forEach((s) => {
    const hasDebt = s.outstanding_balance > 0;
    html += `
      <div class="trx-card" style="cursor:pointer;" onclick="viewSupplierStatement(${s.id})">
        <div class="trx-row">
          <span class="trx-title">🏢 ${s.name}</span>
          <span class="badge-tag ${hasDebt ? 'unpaid' : 'paid'}">
            ${hasDebt ? 'ያልተከፈለ ዕዳ አለበት' : 'ዕዳ የለበትም'}
          </span>
        </div>
        <div class="trx-row">
          <span style="font-size:0.8rem; color:var(--text-secondary);">📞 ${s.phone || '-'} | 📍 ${s.address || '-'}</span>
          <span style="font-weight:800; font-size:1rem; color:${hasDebt ? 'var(--danger)' : 'var(--primary)'};">
            ${formatMoney(s.outstanding_balance)}
          </span>
        </div>
        <div style="text-align:right; font-size:0.75rem; color:var(--primary); font-weight:700; margin-top:2px;">
          ዝርዝር ሂሳብ እና ክፍያ እይ →
        </div>
      </div>
    `;
  });
  container.innerHTML = html;
}

async function handleCreateSupplier(e) {
  e.preventDefault();
  const name = document.getElementById('sup-name').value.trim();
  const phone = document.getElementById('sup-phone').value.trim();
  const address = document.getElementById('sup-address').value.trim();
  const notes = document.getElementById('sup-notes').value.trim();

  try {
    const res = await API.request('contacts.php?action=create', {
      method: 'POST',
      body: JSON.stringify({ type: 'supplier', name, phone, address, notes })
    });
    if (res.success) {
      alert('✅ አቅራቢው ተመዝግቧል');
      closeModal('modal-new-supplier');
      loadSuppliers();
    } else {
      alert(res.error || 'መመዝገብ አልተሳካም');
    }
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

async function handleCreateCustomer(e) {
  e.preventDefault();
  const name = document.getElementById('cust-name').value.trim();
  const phone = document.getElementById('cust-phone').value.trim();
  const address = document.getElementById('cust-address').value.trim();

  if (!name) {
    alert('እባክዎ የደንበኛ ስም ያስገቡ');
    return;
  }

  let createdId = null;
  const payload = { type: 'customer', name, phone, address, outstanding_balance: 0 };

  try {
    if (API.isOnline) {
      const res = await API.request('contacts.php?action=create', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      if (res.success) {
        createdId = res.data.contact_id;
        payload.id = createdId;
      } else {
        alert(res.error || 'ደንበኛውን መመዝገብ አልተሳካም');
        return;
      }
    } else {
      // ከመስመር ውጭ ምዝገባ (Offline)
      const saved = await DB.saveLocalCustomer(payload);
      createdId = saved.id;
    }

    // ወደ አካባቢያዊ መሸጎጫ ማከል
    if (!globalCustomers.some((c) => c.id === createdId)) {
      globalCustomers.unshift(payload);
      await DB.cacheContacts(globalCustomers);
    }

    alert('✅ ደንበኛው ' + name + ' በተሳካ ሁኔታ ተመዝግቧል');
    closeModal('modal-new-customer');

    // ፎርሙን ማጽዳት
    document.getElementById('cust-name').value = '';
    document.getElementById('cust-phone').value = '';
    document.getElementById('cust-address').value = '';

    // በ POS dropdown ውስጥ መምረጥ
    populateCustomerDropdowns(createdId);

    // የደንበኛ ዱቤ ገጽ ላይ ማደስ
    loadCustomerDebts();
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

// =========================================================================
// 13.1 የደንበኞች ዱቤ እና የብድር መቆጣጠሪያ (Customer Debt & Credit Management Controller)
// =========================================================================
let currentCustomerStatementData = null;

async function loadCustomerDebts() {
  // 1. መጀመሪያ ከአካባቢው (IndexedDB)
  const localCusts = await DB.getLocalContacts('customer');
  if (localCusts && localCusts.length > 0) {
    globalCustomers = localCusts;
    renderCustomerDebtsUI(globalCustomers);
  }

  // 2. ኦንላይን ከሆንን ከሰርቨር API ማምጣት
  if (API.isOnline) {
    try {
      const res = await API.request('contacts.php?action=list&type=customer');
      if (res.success) {
        globalCustomers = res.data.contacts || [];
        await DB.cacheContacts(globalCustomers);
        renderCustomerDebtsUI(globalCustomers);
      }
    } catch (e) {
      console.error('Customer debts load error:', e);
    }
  }
}

function renderCustomerDebtsUI(customers) {
  const container = document.getElementById('customer-debts-list-container');
  const totalDebtEl = document.getElementById('cust-total-unpaid-debt');
  const debtorsCountEl = document.getElementById('cust-total-debtors-count');
  const badgeCountEl = document.getElementById('cust-badge-count');

  if (!container) return;

  const list = customers || [];
  let totalDebt = 0;
  let debtorsCount = 0;

  list.forEach((c) => {
    const bal = parseFloat(c.outstanding_balance || 0);
    if (bal > 0) {
      totalDebt += bal;
      debtorsCount++;
    }
  });

  if (totalDebtEl) totalDebtEl.textContent = formatMoney(totalDebt);
  if (debtorsCountEl) debtorsCountEl.textContent = debtorsCount + ' ደንበኞች';
  if (badgeCountEl) badgeCountEl.textContent = list.length + ' ደንበኞች';

  if (list.length === 0) {
    container.innerHTML = '<p style="color:var(--text-muted); text-align:center; padding:20px; font-size:0.85rem;">ምንም የተመዘገበ ደንበኛ የለም። አዲስ ደንበኛ ይመዝግቡ።</p>';
    return;
  }

  // ዕዳ ያለባቸውን ደንበኞች አስቀድሞ ማሳየት
  const sorted = [...list].sort((a, b) => parseFloat(b.outstanding_balance || 0) - parseFloat(a.outstanding_balance || 0));

  let html = '<div style="display:flex; flex-direction:column; gap:8px;">';
  sorted.forEach((c) => {
    const bal = parseFloat(c.outstanding_balance || 0);
    const hasDebt = bal > 0;
    html += `
      <div class="trx-card" style="border-left: 4px solid ${hasDebt ? 'var(--danger)' : 'var(--primary)'};">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
          <div>
            <div style="font-weight:800; font-size:0.95rem; color:var(--text-main);">${c.name}</div>
            <div style="font-size:0.8rem; color:var(--text-secondary); margin-top:2px;">
              📞 ${c.phone || 'ስልክ የለም'} ${c.address ? ' | 📍 ' + c.address : ''}
            </div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:0.75rem; color:var(--text-muted);">ቀሪ ያልተከፈለ ዕዳ</div>
            <div style="font-size:1.05rem; font-weight:800; color:${hasDebt ? 'var(--danger)' : 'var(--primary)'};">
              ${formatMoney(bal)}
            </div>
            <span class="badge-tag ${hasDebt ? 'unpaid' : 'paid'}" style="margin-top:4px;">
              ${hasDebt ? 'ዱቤ አለበት' : 'ንጹህ ሂሳብ'}
            </span>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:10px; border-top:1px dashed var(--border-color); padding-top:8px;">
          <button class="btn btn-primary btn-sm" onclick="viewCustomerDebtDetail(${c.id})" style="font-size:0.8rem;">
            📋 ዝርዝር እና ክፍያ
          </button>
        </div>
      </div>
    `;
  });
  html += '</div>';
  container.innerHTML = html;
}

function filterCustomerDebtsList() {
  const q = document.getElementById('cust-search-input')?.value.toLowerCase().trim() || '';
  if (!q) {
    renderCustomerDebtsUI(globalCustomers);
    return;
  }
  const filtered = globalCustomers.filter((c) => {
    const nameMatch = (c.name || '').toLowerCase().includes(q);
    const phoneMatch = (c.phone || '').toLowerCase().includes(q);
    return nameMatch || phoneMatch;
  });
  renderCustomerDebtsUI(filtered);
}

async function viewCustomerDebtDetail(customerId) {
  const cust = globalCustomers.find((c) => c.id == customerId);
  if (!cust) return;

  // ሞዳሉን ከፍተን ርዕሱን እንሙላ
  openModal('modal-customer-statement');
  document.getElementById('cust-stmt-name').textContent = cust.name + ' - የዱቤ ሂሳብ መግለጫ';
  document.getElementById('cust-stmt-phone').textContent = 'ስልክ፡ ' + (cust.phone || 'የለም') + (cust.address ? ' | አድራሻ፡ ' + cust.address : '');
  document.getElementById('cust-pay-contact-id').value = cust.id;
  document.getElementById('cust-pay-notes').value = '';

  const unpaidBal = parseFloat(cust.outstanding_balance || 0);
  document.getElementById('cust-stmt-unpaid-debt').textContent = formatMoney(unpaidBal);
  document.getElementById('cust-pay-amount').value = unpaidBal > 0 ? unpaidBal : '';
  calculateRemainingCustomerDebt();

  // አካውንቶች dropdown መሙላት
  populateAccountDropdowns();

  // መረጃዎችን ከ API ማምጣት
  const ordersContainer = document.getElementById('cust-stmt-orders-container');
  const paymentsContainer = document.getElementById('cust-stmt-payments-container');
  ordersContainer.innerHTML = '<p style="text-align:center; padding:12px; font-size:0.8rem; color:var(--text-muted);">መረጃዎች በመጫን ላይ...</p>';
  paymentsContainer.innerHTML = '<p style="text-align:center; padding:12px; font-size:0.8rem; color:var(--text-muted);">ክፍያዎች በመጫን ላይ...</p>';

  if (API.isOnline) {
    try {
      const res = await API.request(`contacts.php?action=customer_statement&customer_id=${customerId}`);
      if (res.success) {
        currentCustomerStatementData = res.data;
        renderCustomerStatementDetails(res.data);
        return;
      }
    } catch (e) {
      console.error('Customer statement error:', e);
    }
  }

  // ከመስመር ውጭ ከሆነ (Offline Fallback)
  renderOfflineCustomerStatementDetails(cust);
}

function renderCustomerStatementDetails(data) {
  const summary = data.summary || {};
  document.getElementById('cust-stmt-total-credit').textContent = formatMoney(summary.total_sales_amount || 0);
  document.getElementById('cust-stmt-total-paid').textContent = formatMoney(summary.total_payments_received || 0);
  document.getElementById('cust-stmt-unpaid-debt').textContent = formatMoney(summary.outstanding_balance || 0);
  document.getElementById('cust-stmt-orders-badge').textContent = (data.sales || []).length + ' ትዕዛዞች';
  document.getElementById('cust-stmt-payments-badge').textContent = (data.payments || []).length + ' ክፍያዎች';

  // 1. የተወሰዱ ምግቦችና ትዕዛዞች
  const ordersContainer = document.getElementById('cust-stmt-orders-container');
  const sales = data.sales || [];
  if (sales.length === 0) {
    ordersContainer.innerHTML = '<p style="text-align:center; padding:12px; font-size:0.8rem; color:var(--text-muted);">ምንም የተመዘገበ የዱቤ ትዕዛዝ የለም</p>';
  } else {
    let html = '<div style="display:flex; flex-direction:column; gap:6px;">';
    sales.forEach((s) => {
      const itemsText = (s.items || []).map((it) => `${it.food_name_am} x${it.quantity} (${formatMoney(it.total_price)})`).join(', ') || 'ምግብ';
      html += `
        <div style="background:#fff; border:1px solid var(--border-color); padding:8px 10px; border-radius:var(--radius-sm); font-size:0.82rem;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span style="font-weight:700; color:var(--text-main);">ደረሰኝ #${s.receipt_number}</span>
            <span style="font-size:0.75rem; color:var(--text-muted);">${s.sale_date}</span>
          </div>
          <div style="color:var(--text-secondary); margin:4px 0; font-size:0.78rem;">
            🍽️ <strong>የተወሰዱ ምግቦች፡</strong> ${itemsText}
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px;">
            <span style="font-weight:800; color:var(--primary);">ድምር፡ ${formatMoney(s.grand_total)}</span>
            <button class="btn btn-outline-primary btn-sm" onclick='showSaleReceiptModal(${JSON.stringify(s).replace(/'/g, "&#39;")})' style="padding:2px 8px; font-size:0.75rem;">
              🧾 የዱቤ ደረሰኝ
            </button>
          </div>
        </div>
      `;
    });
    html += '</div>';
    ordersContainer.innerHTML = html;
  }

  // 2. የተፈጸሙ ክፍያዎች
  const paymentsContainer = document.getElementById('cust-stmt-payments-container');
  const payments = data.payments || [];
  if (payments.length === 0) {
    paymentsContainer.innerHTML = '<p style="text-align:center; padding:12px; font-size:0.8rem; color:var(--text-muted);">እስካሁን የተፈጸመ ክፍያ የለም</p>';
  } else {
    let html = '<div style="display:flex; flex-direction:column; gap:6px;">';
    payments.forEach((p) => {
      html += `
        <div style="background:#fff; border:1px solid #bbf7d0; padding:8px 10px; border-radius:var(--radius-sm); font-size:0.82rem;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span style="font-weight:700; color:#166534;">ክፍያ #${p.reference_id}</span>
            <span style="font-size:0.75rem; color:var(--text-muted);">${p.payment_date}</span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px;">
            <span>በ${p.account_name_am || 'ጥሬ ገንዘብ'} | ${p.notes || 'ዕዳ ክፍያ'}</span>
            <span style="font-weight:800; color:#059669;">+${formatMoney(p.amount)}</span>
          </div>
          <div style="text-align:right; margin-top:4px;">
            <button class="btn btn-outline-primary btn-sm" onclick='showPaymentVoucherModal(${JSON.stringify(p).replace(/'/g, "&#39;")}, "${data.customer?.name || ''}")' style="padding:2px 8px; font-size:0.75rem;">
              🧾 የክፍያ ደረሰኝ
            </button>
          </div>
        </div>
      `;
    });
    html += '</div>';
    paymentsContainer.innerHTML = html;
  }
}

function renderOfflineCustomerStatementDetails(cust) {
  const bal = parseFloat(cust.outstanding_balance || 0);
  document.getElementById('cust-stmt-total-credit').textContent = formatMoney(bal);
  document.getElementById('cust-stmt-total-paid').textContent = '0.00 ETB';
  document.getElementById('cust-stmt-unpaid-debt').textContent = formatMoney(bal);
  document.getElementById('cust-stmt-orders-badge').textContent = 'ከመስመር ውጭ';
  document.getElementById('cust-stmt-payments-badge').textContent = 'ከመስመር ውጭ';

  document.getElementById('cust-stmt-orders-container').innerHTML = `
    <div style="padding:10px; font-size:0.82rem; color:var(--text-secondary); text-align:center;">
      ከመስመር ውጭ (Offline) ሁነታ ላይ ነዎት። የቀደሙ ዝርዝሮች ኢንተርኔት ሲገናኝ ሙሉ በሙሉ ይዘረዘራሉ።
      <div style="font-weight:800; margin-top:4px; color:var(--danger);">የአሁኑ ቀሪ ዕዳ፡ ${formatMoney(bal)}</div>
    </div>
  `;
  document.getElementById('cust-stmt-payments-container').innerHTML = '<p style="text-align:center; padding:10px; font-size:0.8rem; color:var(--text-muted);">የክፍያ ታሪክ ከመስመር ውጭ አይገኝም</p>';
}

function calculateRemainingCustomerDebt() {
  const unpaidBal = parseFloat(document.getElementById('cust-stmt-unpaid-debt').textContent.replace(/[^0-9.-]+/g, '')) || 0;
  const payAmt = parseFloat(document.getElementById('cust-pay-amount').value) || 0;
  const rem = Math.max(0, unpaidBal - payAmt);
  const previewEl = document.getElementById('cust-pay-remaining-preview');
  if (previewEl) {
    if (payAmt >= unpaidBal && unpaidBal > 0) {
      previewEl.innerHTML = '<span style="color:#059669; font-weight:800;">✅ ሙሉ ዕዳው ይዘጋል (ቀሪ: 0.00 ETB)</span>';
    } else {
      previewEl.innerHTML = `ቀሪ ያልተከፈለ ዕዳ፡ <strong style="color:var(--danger);">${formatMoney(rem)}</strong> (ከፊል ክፍያ)`;
    }
  }
}

async function handleCustomerDebtPayment(e) {
  e.preventDefault();
  const contactId = parseInt(document.getElementById('cust-pay-contact-id').value);
  const amount = parseFloat(document.getElementById('cust-pay-amount').value) || 0;
  const accountId = parseInt(document.getElementById('cust-pay-account-id').value);
  const notes = document.getElementById('cust-pay-notes').value.trim();

  if (!contactId || amount <= 0 || !accountId) {
    alert('እባክዎ ትክክለኛ የክፍያ መጠን እና አካውንት ይምረጡ');
    return;
  }

  const payload = {
    contact_id: contactId,
    amount: amount,
    account_id: accountId,
    notes: notes,
    payment_date: new Date().toISOString().slice(0, 19).replace('T', ' ')
  };

  try {
    let receiptData = null;
    if (API.isOnline) {
      const res = await API.request('contacts.php?action=settle_debt', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      if (res.success) {
        receiptData = res.data;
      } else {
        alert(res.error || 'ክፍያውን መመዝገብ አልተሳካም');
        return;
      }
    } else {
      // ከመስመር ውጭ (Offline Settlement)
      await DB.processLocalCustomerDebtPayment(payload);
      const cust = globalCustomers.find((c) => c.id == contactId);
      const prevBal = parseFloat(cust?.outstanding_balance || 0);
      const newBal = Math.max(0, prevBal - amount);
      if (cust) cust.outstanding_balance = newBal;
      receiptData = {
        contact_id: contactId,
        contact_name: cust?.name || 'ደንበኛ',
        contact_phone: cust?.phone || '',
        previous_balance: prevBal,
        amount_paid: amount,
        new_balance: newBal,
        payment_reference: 'offline-pay-' + Date.now(),
        payment_date: payload.payment_date,
        account_name_am: 'ጥሬ ገንዘብ / አካውንት',
        notes: notes
      };
    }

    alert('✅ የ' + formatMoney(amount) + ' የዕዳ ክፍያ በተሳካ ሁኔታ ተመዝግቧል');
    closeModal('modal-customer-statement');

    // ደረሰኝ ማሳየት
    if (receiptData) {
      showPaymentVoucherReceiptModal(receiptData);
    }

    // መረጃዎችን ማደስ
    loadCustomerDebts();
    loadDashboard('today');
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

// =========================================================================
// 13.2 ሊታተሙ የሚችሉ የዱቤ እና የክፍያ ደረሰኞች (Printable Debt & Payment Receipts)
// =========================================================================
function showSaleReceiptModal(sale) {
  const container = document.getElementById('receipt-content-box');
  if (!container) return;

  const itemsHtml = (sale.items || []).map((it) => `
    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
      <span>${it.food_name_am} x${it.quantity}</span>
      <span>${formatMoney(it.total_price)}</span>
    </div>
  `).join('');

  container.innerHTML = `
    <div style="text-align:center; border-bottom:1px dashed #000; padding-bottom:8px; margin-bottom:8px;">
      <h3 style="margin:0; font-size:1.15rem; font-weight:800;">ተክሉ ምግብ ቤት</h3>
      <p style="margin:2px 0; font-size:0.8rem;">የዱቤ ሽያጭ ደረሰኝ (Credit Order Receipt)</p>
      <div style="font-size:0.75rem; color:#444;">ደረሰኝ #: ${sale.receipt_number || sale.id}</div>
      <div style="font-size:0.75rem; color:#444;">ቀን: ${sale.sale_date}</div>
    </div>

    <div style="margin-bottom:8px;">
      <div style="font-weight:700; font-size:0.82rem;">የተወሰዱ ምግቦች ዝርዝር፡</div>
      ${itemsHtml}
    </div>

    <div style="border-top:1px dashed #000; padding-top:6px; margin-top:6px;">
      <div style="display:flex; justify-content:space-between; font-weight:800; font-size:0.95rem;">
        <span>ጠቅላላ ድምር፡</span>
        <span>${formatMoney(sale.grand_total)}</span>
      </div>
      <div style="display:flex; justify-content:space-between; color:#b91c1c; font-weight:700; font-size:0.85rem; margin-top:3px;">
        <span>የክፍያ ሁኔታ፡</span>
        <span>በዱቤ (ያልተከፈለ)</span>
      </div>
    </div>

    <div style="text-align:center; margin-top:12px; font-size:0.75rem; color:#666;">
      እናመሰግናለን! እንደገና ይጎብኙን።
    </div>
  `;
  openModal('modal-receipt');
}

function showPaymentVoucherReceiptModal(payData) {
  const container = document.getElementById('receipt-content-box');
  if (!container) return;

  container.innerHTML = `
    <div style="text-align:center; border-bottom:1px dashed #000; padding-bottom:8px; margin-bottom:8px;">
      <h3 style="margin:0; font-size:1.15rem; font-weight:800;">ተክሉ ምግብ ቤት</h3>
      <p style="margin:2px 0; font-size:0.82rem; font-weight:700; color:#166534;">የዕዳ ክፍያ ደረሰኝ (Payment Receipt)</p>
      <div style="font-size:0.75rem; color:#444;">ማመሳከሪያ #: ${payData.payment_reference || '-'}</div>
      <div style="font-size:0.75rem; color:#444;">ቀን: ${payData.payment_date || new Date().toLocaleString()}</div>
    </div>

    <div style="margin-bottom:8px; font-size:0.85rem;">
      <div style="margin-bottom:4px;"><strong>የደንበኛ ስም፡</strong> ${payData.contact_name || 'ደንበኛ'}</div>
      ${payData.contact_phone ? `<div style="margin-bottom:4px;"><strong>ስልክ፡</strong> ${payData.contact_phone}</div>` : ''}
      <div style="margin-bottom:4px;"><strong>የተከፈለበት አካውንት፡</strong> ${payData.account_name_am || 'ጥሬ ገንዘብ'}</div>
      ${payData.notes ? `<div style="margin-bottom:4px;"><strong>ማስታወሻ፡</strong> ${payData.notes}</div>` : ''}
    </div>

    <div style="border-top:1px dashed #000; border-bottom:1px dashed #000; padding:6px 0; margin:8px 0;">
      <div style="display:flex; justify-content:space-between; font-size:0.85rem;">
        <span>የቀደመ እዳ፡</span>
        <span>${formatMoney(payData.previous_balance || 0)}</span>
      </div>
      <div style="display:flex; justify-content:space-between; font-weight:800; font-size:1.05rem; color:#059669; margin:4px 0;">
        <span>አሁን የተከፈለ፡</span>
        <span>${formatMoney(payData.amount_paid || 0)}</span>
      </div>
      <div style="display:flex; justify-content:space-between; font-weight:800; font-size:0.95rem; color:${payData.new_balance > 0 ? '#b91c1c' : '#059669'};">
        <span>ቀሪ ያልተከፈለ ዕዳ፡</span>
        <span>${formatMoney(payData.new_balance || 0)}</span>
      </div>
    </div>

    <div style="text-align:center; margin-top:12px; font-size:0.75rem; color:#666;">
      ይህ ደረሰኝ ህጋዊ የዕዳ ክፍያ ማረጋገጫ ነው። እናመሰግናለን!
    </div>
  `;
  openModal('modal-receipt');
}

function showPaymentVoucherModal(payment, customerName) {
  showPaymentVoucherReceiptModal({
    contact_name: customerName,
    payment_reference: payment.reference_id,
    payment_date: payment.payment_date,
    account_name_am: payment.account_name_am,
    amount_paid: payment.amount,
    previous_balance: payment.amount,
    new_balance: 0,
    notes: payment.notes
  });
}

// =========================================================================
// 14. ተጠቃሚዎች እና ተጨማሪ ሞጁሎች (Users, Closing, Reports)
// =========================================================================
function showSubModule(subId) {
  document.querySelectorAll('.sub-module').forEach((sm) => sm.style.display = 'none');
  const target = document.getElementById(subId);
  if (target) {
    target.style.display = 'block';
    target.scrollIntoView({ behavior: 'smooth' });
    if (subId === 'sub-users') loadUsers();
    else if (subId === 'sub-closing') loadDailyClosingStatus();
    else if (subId === 'sub-reports') loadReport('pl');
  }
}

function hideSubModules() {
  document.querySelectorAll('.sub-module').forEach((sm) => sm.style.display = 'none');
}

async function loadUsers() {
  try {
    const res = await API.request('auth.php?action=users');
    if (res.success) {
      const users = res.data.users || [];
      const container = document.getElementById('users-list-container');
      let html = '';
      users.forEach((u) => {
        html += `
          <div class="trx-card">
            <div class="trx-row">
              <span class="trx-title">👤 ${u.full_name} (@${u.username})</span>
              <span class="badge-tag ${u.role === 'super_admin' ? 'paid' : 'neutral'}">${u.role}</span>
            </div>
          </div>
        `;
      });
      container.innerHTML = html;
    }
  } catch (e) {
    console.error('Users load error:', e);
  }
}

async function handleCreateUser(e) {
  e.preventDefault();
  const fullName = document.getElementById('nu-fullname').value.trim();
  const username = document.getElementById('nu-username').value.trim();
  const password = document.getElementById('nu-password').value;
  const role = document.getElementById('nu-role').value;

  try {
    const res = await API.request('auth.php?action=create_user', {
      method: 'POST',
      body: JSON.stringify({ full_name: fullName, username, password, role })
    });
    if (res.success) {
      alert('✅ ተጠቃሚው ተፈጥሯል');
      closeModal('modal-new-user');
      loadUsers();
    } else {
      alert(res.error || 'መፍጠር አልተሳካም');
    }
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

async function loadDailyClosingStatus() {
  try {
    const res = await API.request('daily_closing.php?action=status');
    if (res.success) {
      const d = res.data;
      document.getElementById('closing-system-cash').value = formatMoney(d.system_balances.cash);
      calculateClosingDiff();
    }
  } catch (e) {
    console.error('Closing status error:', e);
  }
}

function calculateClosingDiff() {
  const actual = parseFloat(document.getElementById('closing-actual-cash').value) || 0;
  const sysText = document.getElementById('closing-system-cash').value.replace(/[^\d.]/g, '');
  const system = parseFloat(sysText) || 0;
  const diff = actual - system;

  const diffEl = document.getElementById('closing-diff');
  diffEl.value = (diff >= 0 ? '+' : '') + formatMoney(diff);
  diffEl.style.color = diff === 0 ? 'var(--primary)' : 'var(--danger)';
}

async function submitDailyClosing() {
  const actualCash = parseFloat(document.getElementById('closing-actual-cash').value) || 0;
  const actualCbe = parseFloat(document.getElementById('closing-actual-cbe').value) || 0;
  const actualTele = parseFloat(document.getElementById('closing-actual-telebirr').value) || 0;
  const reason = document.getElementById('closing-discrepancy-reason').value.trim();

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
      alert('✅ የዕለቱ ሂሳብ በተሳካ ሁኔታ ተዘግቷል!');
      loadDailyClosingStatus();
    } else {
      alert(res.error || 'ሂሳብ መዝጋት አልተሳካም');
    }
  } catch (err) {
    alert('ስህተት: ' + err.message);
  }
}

async function loadReport(reportType, btnEl = null) {
  if (btnEl) {
    btnEl.parentElement.querySelectorAll('.btn').forEach((b) => b.classList.remove('active'));
    btnEl.classList.add('active');
  }

  const container = document.getElementById('reports-content-container');
  try {
    const res = await API.request(`reports.php?type=${reportType}`);
    if (res.success) {
      if (reportType === 'pl') {
        const s = res.data.statement;
        container.innerHTML = `
          <div style="background:var(--bg-subtle); padding:12px; border-radius:var(--radius-sm); margin-top:10px;">
            <div class="receipt-row"><span>ጠቅላላ ገቢ (Gross Revenue)</span><strong>${formatMoney(s.gross_revenue)}</strong></div>
            <div class="receipt-row"><span>የተሸጡ እቃዎች ወጪ (COGS)</span><span style="color:var(--danger);">${formatMoney(s.cost_of_goods_sold)}</span></div>
            <div class="receipt-row" style="border-top:1px dashed var(--border-color); padding-top:4px;"><span>ጠቅላላ ትርፍ (Gross Profit)</span><strong style="color:var(--primary);">${formatMoney(s.gross_profit)} (${s.gross_margin_percentage}%)</strong></div>
            <div class="receipt-row"><span>የስራ ማስኬጃ ወጪዎች (Expenses)</span><span style="color:var(--danger);">${formatMoney(s.operating_expenses)}</span></div>
            <div class="receipt-row" style="font-size:1.05rem; font-weight:800; border-top:2px solid var(--border-color); padding-top:6px;"><span>የተጣራ ትርፍ (Net Profit)</span><strong style="color:var(--primary);">${formatMoney(s.net_profit)} (${s.net_margin_percentage}%)</strong></div>
            <div class="receipt-row" style="font-size:0.8rem; color:var(--warning); margin-top:8px;"><span>የወጣ ገንዘብ (Withdrawals)</span><span>${formatMoney(s.owner_withdrawals)}</span></div>
          </div>
        `;
      } else if (reportType === 'product_profitability') {
        let rows = '';
        (res.data.product_profitability || []).forEach((p) => {
          rows += `<tr><td>${p.name_am}</td><td>${p.sold_qty} ${p.unit}</td><td>${formatMoney(p.total_sales)}</td><td style="color:var(--primary); font-weight:700;">${formatMoney(p.profit)}</td></tr>`;
        });
        container.innerHTML = `
          <div class="table-responsive" style="margin-top:10px;">
            <table class="app-table">
              <thead><tr><th>ምርት</th><th>የተሸጠ</th><th>ሽያጭ</th><th>ትርፍ</th></tr></thead>
              <tbody>${rows || '<tr><td colspan="4" style="text-align:center;">ዳታ የለም</td></tr>'}</tbody>
            </table>
          </div>
        `;
      } else if (reportType === 'cashflow') {
        const d = res.data;
        container.innerHTML = `
          <div style="background:var(--bg-subtle); padding:12px; border-radius:var(--radius-sm); margin-top:10px;">
            <div class="receipt-row" style="color:var(--primary);"><span>ጠቅላላ ገቢ ፍሰት (Inflow)</span><strong>${formatMoney(d.total_inflow)}</strong></div>
            <div class="receipt-row" style="color:var(--danger);"><span>ጠቅላላ ወጪ ፍሰት (Outflow)</span><strong>${formatMoney(d.total_outflow)}</strong></div>
            <div class="receipt-row" style="font-size:1rem; font-weight:800; border-top:1px dashed var(--border-color); padding-top:4px;"><span>የተጣራ የገንዘብ ፍሰት (Net Cash Flow)</span><strong>${formatMoney(d.net_cash_flow)}</strong></div>
          </div>
        `;
      }
    }
  } catch (e) {
    console.error('Report error:', e);
  }
}

// =========================================================================
// 15. ረዳት ተግባራት (Helpers & Dropdown Fillers)
// =========================================================================
function populateCategoryDropdowns() {
  const npCat = document.getElementById('np-category');
  if (npCat) {
    npCat.innerHTML = globalCategories.map((c) => `<option value="${c.id}">${c.name_am} (${c.name_en})</option>`).join('');
  }
  const expCat = document.getElementById('exp-category');
  if (expCat) {
    expCat.innerHTML = globalCategories.filter((c) => c.type === 'expense').map((c) => `<option value="${c.id}">${c.name_am}</option>`).join('');
  }
}

function populateSupplierDropdowns() {
  const sel = document.getElementById('rec-supplier-select');
  if (sel) {
    let opts = '<option value="">-- ነባር አቅራቢ ይምረጡ --</option>';
    globalSuppliers.forEach((s) => {
      opts += `<option value="${s.id}">${s.name} (${s.phone || 'ስልክ የለም'})</option>`;
    });
    opts += '<option value="NEW">+ አዲስ አቅራቢ መመዝገብ</option>';
    sel.innerHTML = opts;
  }
}

function populateCustomerDropdowns(selectedId = null) {
  const sel = document.getElementById('pos-customer-select');
  if (sel) {
    let opts = '<option value="">-- ደንበኛ ይምረጡ --</option>';
    globalCustomers.forEach((c) => {
      const isSel = (selectedId && c.id == selectedId) ? 'selected' : '';
      const debtText = parseFloat(c.outstanding_balance || 0) > 0 ? ` [ዕዳ፡ ${formatMoney(c.outstanding_balance)}]` : '';
      opts += `<option value="${c.id}" ${isSel}>${c.name} (${c.phone || 'ስልክ የለም'})${debtText}</option>`;
    });
    sel.innerHTML = opts;
  }
}

function populateAccountDropdowns() {
  const ids = ['rec-payment-account', 'sp-account-id', 'wth-account', 'exp-account', 'cust-pay-account-id'];
  const accOptions = globalAccounts.map((a) => `<option value="${a.id}">${a.name_am} (${formatMoney(a.current_balance)})</option>`).join('');

  ids.forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = accOptions;
  });

  const adjProd = document.getElementById('adj-product');
  if (adjProd) {
    adjProd.innerHTML = globalProducts.map((p) => `<option value="${p.id}">${p.name_am} (ያለ: ${p.total_stock} ${p.unit})</option>`).join('');
  }
}

function openModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    modal.classList.add('active');
    if (id === 'modal-create-food-recipe') {
      const container = document.getElementById('recipe-ingredients-container');
      if (container && container.children.length === 0) {
        addRecipeIngredientRow();
      }
    }
  }
}

function openModal(id) {
  document.getElementById(id)?.classList.add('active');
}

function closeModal(id) {
  document.getElementById(id)?.classList.remove('active');
}

function formatMoney(val) {
  const num = parseFloat(val) || 0;
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ETB';
}

function bin2hex(length) {
  let result = '';
  const characters = 'abcdef0123456789';
  for (let i = 0; i < length * 2; i++) {
    result += characters.charAt(Math.floor(Math.random() * characters.length));
  }
  return result;
}

// =========================================================================
// 16. PWA እና የሞባይል ጭነት ቁጥጥር (PWA Phone Installation & Service Worker)
// =========================================================================
let deferredInstallPrompt = null;

// Service Worker ምዝገባ
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./sw.js')
      .then((reg) => {
        console.log('Teklu Food Service Worker registered with scope:', reg.scope);
      })
      .catch((err) => {
        console.warn('Service Worker registration failed:', err);
      });
  });
}

// አንድሮይድ / የሞባይል አሳሽ ጭነት ጥያቄ (Install Prompt)
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredInstallPrompt = e;

  // የመጫኛ አዝራሮችንና ባነሮችን አሳይ
  const installBtn = document.getElementById('btn-install-pwa');
  if (installBtn) installBtn.style.display = 'inline-flex';

  const banner = document.getElementById('pwa-install-banner');
  if (banner && !sessionStorage.getItem('pwa_banner_dismissed')) {
    banner.style.display = 'flex';
  }
});

// አፕሊኬሽኑን በስልክ ላይ መጫን
async function triggerPwaInstall() {
  if (!deferredInstallPrompt) {
    alert('በስልክዎ አሳሽ (Chrome/Edge) የላይኛው 3 ነጥቦች (⋮) በመንካት "Add to Home Screen" ወይም "Install App" የሚለውን ይጫኑ።');
    return;
  }

  deferredInstallPrompt.prompt();
  const choiceResult = await deferredInstallPrompt.userChoice;
  if (choiceResult.outcome === 'accepted') {
    console.log('User accepted the PWA install prompt');
    dismissPwaBanner();
  }
  deferredInstallPrompt = null;
}

function dismissPwaBanner() {
  const banner = document.getElementById('pwa-install-banner');
  if (banner) banner.style.display = 'none';
  sessionStorage.setItem('pwa_banner_dismissed', 'true');
}

window.addEventListener('appinstalled', () => {
  console.log('Teklu Food App was installed successfully');
  dismissPwaBanner();
  const installBtn = document.getElementById('btn-install-pwa');
  if (installBtn) installBtn.style.display = 'none';
});

