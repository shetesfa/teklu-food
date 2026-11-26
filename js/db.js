/**
 * ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
 * የአካባቢ ዳታቤዝ እና የኦፍላይን ማከማቻ ሞተር (IndexedDB Manager - Offline-First)
 * ኦንላይንም ሆነ ኦፍላይን ሁልጊዜ አንድ ዓይነት እይታ (Same View Always) እንዲኖር ዋስትና ይሰጣል
 */

const DB = {
  dbName: 'TekluBusinessDB',
  dbVersion: 2,
  instance: null,
  _initPromise: null,

  async init() {
    if (this.instance) return this.instance;
    // Single-flight: loadInitialData() used to open the DB 6 times at once
    // (one per lazy getLocal* call), racing and leaving stale connections
    // that later threw "The database connection is closing" on transaction().
    if (this._initPromise) return this._initPromise;

    this._initPromise = new Promise((resolve, reject) => {
      const request = indexedDB.open(this.dbName, this.dbVersion);

      request.onupgradeneeded = (event) => {
        const db = event.target.result;

        // 1. ምርቶች እና ጥሬ ዕቃዎች
        if (!db.objectStoreNames.contains('products')) {
          db.createObjectStore('products', { keyPath: 'id' });
        }

        // 2. የምርት ምድቦች
        if (!db.objectStoreNames.contains('categories')) {
          db.createObjectStore('categories', { keyPath: 'id' });
        }

        // 3. የምግብ ሬሲፒዎች እና ግብዓቶቻቸው
        if (!db.objectStoreNames.contains('recipes')) {
          db.createObjectStore('recipes', { keyPath: 'id' });
        }

        // 4. ደንበኞች እና አቅራቢዎች (Contacts)
        if (!db.objectStoreNames.contains('contacts')) {
          const contactStore = db.createObjectStore('contacts', { keyPath: 'id' });
          contactStore.createIndex('type', 'type', { unique: false });
        }

        // 5. የፋይናንስ ሂሳቦች (ካሽ፣ ባንክ፣ ቴሌብር)
        if (!db.objectStoreNames.contains('financial_accounts')) {
          db.createObjectStore('financial_accounts', { keyPath: 'id' });
        }

        // 6. የእቃ መረከቢያ ታሪክ (Purchases)
        if (!db.objectStoreNames.contains('purchases')) {
          db.createObjectStore('purchases', { keyPath: 'id' });
        }

        // 7. የሽያጭ ታሪክ (Sales)
        if (!db.objectStoreNames.contains('sales')) {
          db.createObjectStore('sales', { keyPath: 'id' });
        }

        // 8. የገንዘብ መውጣት ታሪክ (Withdrawals)
        if (!db.objectStoreNames.contains('withdrawals')) {
          db.createObjectStore('withdrawals', { keyPath: 'id' });
        }

        // 9. የስራ ማስኬጃ ወጪዎች ታሪክ (Expenses)
        if (!db.objectStoreNames.contains('expenses')) {
          db.createObjectStore('expenses', { keyPath: 'id' });
        }

        // 10. የዳሽቦርድ ስታቲስቲክስ መሸጎጫ (Dashboard Summary)
        if (!db.objectStoreNames.contains('dashboard_summary')) {
          db.createObjectStore('dashboard_summary', { keyPath: 'filter' });
        }

        // 11. ከመስመር ውጭ የማመሳሰያ ወረፋ (Offline Sync Queue)
        if (!db.objectStoreNames.contains('sync_queue')) {
          const syncStore = db.createObjectStore('sync_queue', { keyPath: 'uuid' });
          syncStore.createIndex('status', 'status', { unique: false });
          syncStore.createIndex('created_at', 'created_at', { unique: false });
        }

        // 12. የተጠቃሚ ምስክር ወረቀት (Auth Cache)
        if (!db.objectStoreNames.contains('auth_cache')) {
          db.createObjectStore('auth_cache', { keyPath: 'key' });
        }
      };

      request.onsuccess = (event) => {
        this.instance = event.target.result;
        // If another tab upgrades the DB, the browser closes this
        // connection: drop it so the next call re-opens a fresh one
        // instead of throwing "The database connection is closing".
        this.instance.onversionchange = () => { this.instance.close(); this.instance = null; };
        this.instance.onclose = () => { this.instance = null; };
        resolve(this.instance);
      };

      request.onerror = (event) => {
        this._initPromise = null;
        reject(event.target.error);
      };

      request.onblocked = () => {
        console.warn('IndexedDB open blocked by another tab');
      };
    });
    return this._initPromise;
  },

  // =========================================================================
  // ረዳት ተግባራት - ሁለንተናዊ CRUD ለ IndexedDB
  // =========================================================================
  async _getAll(storeName) {
    if (!this.instance) await this.init();
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction(storeName, 'readonly');
      const store = tx.objectStore(storeName);
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  },

  async _putAll(storeName, items) {
    if (!this.instance) await this.init();
    if (!Array.isArray(items) || items.length === 0) return;
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction(storeName, 'readwrite');
      const store = tx.objectStore(storeName);
      for (const item of items) {
        store.put(item);
      }
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  },

  async _putOne(storeName, item) {
    if (!this.instance) await this.init();
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction(storeName, 'readwrite');
      const store = tx.objectStore(storeName);
      const req = store.put(item);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  },

  async _getOne(storeName, key) {
    if (!this.instance) await this.init();
    return new Promise((resolve) => {
      const tx = this.instance.transaction(storeName, 'readonly');
      const store = tx.objectStore(storeName);
      const req = store.get(key);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => resolve(null);
    });
  },

  // =========================================================================
  // 1. ምርቶች እና ምድቦች (Products & Categories)
  // =========================================================================
  async cacheProducts(products) {
    return this._putAll('products', products);
  },

  async getLocalProducts() {
    return this._getAll('products');
  },

  async cacheCategories(categories) {
    return this._putAll('categories', categories);
  },

  async getLocalCategories() {
    return this._getAll('categories');
  },

  // =========================================================================
  // 2. የምግብ ሬሲፒዎች (Food Recipes)
  // =========================================================================
  async cacheRecipes(recipes) {
    return this._putAll('recipes', recipes);
  },

  async getLocalRecipes() {
    return this._getAll('recipes');
  },

  // =========================================================================
  // 3. ደንበኞች እና አቅራቢዎች (Contacts)
  // =========================================================================
  async cacheContacts(contacts) {
    return this._putAll('contacts', contacts);
  },

  async getLocalContacts(type = null) {
    const all = await this._getAll('contacts');
    if (!type) return all;
    return all.filter((c) => c.type === type);
  },

  // =========================================================================
  // 4. የፋይናንስ ሂሳቦች (Financial Accounts)
  // =========================================================================
  async cacheAccounts(accounts) {
    return this._putAll('financial_accounts', accounts);
  },

  async getLocalAccounts() {
    return this._getAll('financial_accounts');
  },

  // =========================================================================
  // 5. እቃ መረከቢያ (Purchases / Receiving)
  // =========================================================================
  async cachePurchases(purchases) {
    return this._putAll('purchases', purchases);
  },

  async getLocalPurchases() {
    const list = await this._getAll('purchases');
    // በቅርብ ጊዜ ቅደም ተከተል
    return list.sort((a, b) => new Date(b.created_at || b.purchase_date) - new Date(a.created_at || a.purchase_date));
  },

  // =========================================================================
  // 6. ሽያጭ (Sales)
  // =========================================================================
  async cacheSales(sales) {
    return this._putAll('sales', sales);
  },

  async getLocalSales() {
    const list = await this._getAll('sales');
    return list.sort((a, b) => new Date(b.created_at || b.sale_date) - new Date(a.created_at || a.sale_date));
  },

  // =========================================================================
  // 7. ገንዘብ መውጣት (Withdrawals)
  // =========================================================================
  async cacheWithdrawals(withdrawals) {
    return this._putAll('withdrawals', withdrawals);
  },

  async getLocalWithdrawals() {
    const list = await this._getAll('withdrawals');
    return list.sort((a, b) => new Date(b.created_at || b.withdrawal_date) - new Date(a.created_at || a.withdrawal_date));
  },

  // =========================================================================
  // 8. የስራ ማስኬጃ ወጪዎች (Expenses)
  // =========================================================================
  async cacheExpenses(expenses) {
    return this._putAll('expenses', expenses);
  },

  async getLocalExpenses() {
    const list = await this._getAll('expenses');
    return list.sort((a, b) => new Date(b.created_at || b.expense_date) - new Date(a.created_at || a.expense_date));
  },

  // =========================================================================
  // 9. የዳሽቦርድ ስታቲስቲክስ (Dashboard Summary)
  // =========================================================================
  async cacheDashboard(filter, data) {
    return this._putOne('dashboard_summary', { filter, data, updated_at: Date.now() });
  },

  async getLocalDashboard(filter = 'today') {
    const row = await this._getOne('dashboard_summary', filter);
    return row ? row.data : null;
  },

  // =========================================================================
  // 10. ከመስመር ውጭ የማመሳሰያ ወረፋ (Sync Queue)
  // =========================================================================
  async enqueueTransaction(type, payload) {
    if (!this.instance) await this.init();
    const uuid = payload.uuid || ('offline-' + type + '-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9));
    payload.uuid = uuid;

    const item = {
      uuid: uuid,
      type: type,
      payload: payload,
      status: 'pending',
      created_at: new Date().toISOString()
    };

    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('sync_queue', 'readwrite');
      const store = tx.objectStore('sync_queue');
      const req = store.put(item);
      req.onsuccess = () => resolve(item);
      req.onerror = () => reject(req.error);
    });
  },

  async getPendingTransactions() {
    if (!this.instance) await this.init();
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('sync_queue', 'readonly');
      const store = tx.objectStore('sync_queue');
      const req = store.getAll();
      req.onsuccess = () => {
        const all = req.result || [];
        resolve(all.filter((it) => it.status === 'pending'));
      };
      req.onerror = () => reject(req.error);
    });
  },

  async markTransactionsSynced(syncedUuids) {
    if (!this.instance || !syncedUuids || syncedUuids.length === 0) return;
    return new Promise((resolve, reject) => {
      const tx = this.instance.transaction('sync_queue', 'readwrite');
      const store = tx.objectStore('sync_queue');
      for (const uuid of syncedUuids) {
        store.delete(uuid);
      }
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  },

  async getPendingCount() {
    const pending = await this.getPendingTransactions();
    return pending.length;
  },

  // =========================================================================
  // 11. የተጠቃሚ ምስክር ወረቀት (Auth Cache)
  // =========================================================================
  async cacheAuth(token, user) {
    if (!this.instance) await this.init();
    return this._putOne('auth_cache', { key: 'current_auth', token, user, saved_at: Date.now() });
  },

  async getCachedAuth() {
    return this._getOne('auth_cache', 'current_auth');
  },

  // =========================================================================
  // 12. ከመስመር ውጭ በቅጽበት እይታን ማደሻ (Optimistic Offline Local Updates)
  // =========================================================================

  /**
   * ከመስመር ውጭ የተከናወነን ሽያጭ በአካባቢው ዳታቤዝ ላይ ወዲያውኑ ማስተካከል
   */
  async applyOfflineSale(salePayload, recipeList) {
    if (!this.instance) await this.init();

    // 1. ምርቶችን እና ጥሬ ዕቃዎችን ከክምችት መቀነስ
    for (const item of salePayload.items) {
      const pId = parseInt(item.product_id);
      const qty = parseFloat(item.quantity);

      // ሬሲፒ አለው ወይ?
      const recipe = (recipeList || []).find((r) => parseInt(r.product_id) === pId || parseInt(r.id) === pId);
      if (recipe && recipe.ingredients && recipe.ingredients.length > 0) {
        // የሬሲፒ ግብዓቶችን መቀነስ
        for (const ing of recipe.ingredients) {
          const ingProdId = parseInt(ing.product_id);
          const reqQty = parseFloat(ing.quantity) * qty;
          await this._adjustProductStock(ingProdId, -reqQty);
        }
      } else {
        // ተራ ምርት
        await this._adjustProductStock(pId, -qty);
      }
    }

    // 2. የተከፈለውን ወደ ፋይናንስ አካውንት መደመር
    const paid = parseFloat(salePayload.paid_amount || 0);
    if (paid > 0 && salePayload.account_id) {
      await this._adjustAccountBalance(parseInt(salePayload.account_id), paid);
    }

    // 3. ብድር ካለ በደንበኛው ላይ መደመር
    const unpaid = parseFloat(salePayload.grand_total) - paid;
    if (unpaid > 0 && salePayload.customer_id) {
      await this._adjustContactBalance(parseInt(salePayload.customer_id), unpaid);
    }

    // 4. ሽያጩን በአካባቢው የሽያጭ ታሪክ መዝገብ ማስቀመጥ
    const offlineSaleRecord = {
      id: 'local-' + Date.now(),
      receipt_number: 'REC-OFF-' + Math.floor(1000 + Math.random() * 9000),
      sale_date: new Date().toISOString().replace('T', ' ').substring(0, 19),
      grand_total: parseFloat(salePayload.grand_total),
      paid_amount: paid,
      unpaid_balance: unpaid,
      payment_method: salePayload.payment_method || 'cash',
      payment_status: unpaid <= 0 ? 'paid' : (paid > 0 ? 'partial' : 'credit'),
      customer_name: salePayload.customer_name || 'ተራ ደንበኛ',
      items: salePayload.items,
      is_offline: true,
      created_at: new Date().toISOString()
    };
    await this._putOne('sales', offlineSaleRecord);

    // 5. ዳሽቦርድ ላይ የቀኑን ሽያጭ መደመር
    await this._adjustDashboardMetrics({
      sales: parseFloat(salePayload.grand_total),
      liquid: paid,
      customer_debt: unpaid
    });

    // 6. ግብይቱን ወደ ወረፋ ማስገባት
    await this.enqueueTransaction('sale', salePayload);

    return offlineSaleRecord;
  },

  /**
   * ከመስመር ውጭ የተከናወነን እቃ መረከቢያ በአካባቢው ዳታቤዝ ላይ ወዲያውኑ ማስተካከል
   */
  async applyOfflineReceiving(receivingPayload) {
    if (!this.instance) await this.init();

    // 1. የተረከቧቸውን ምርቶች ክምችት መደመር
    for (const item of receivingPayload.items) {
      const pId = parseInt(item.product_id);
      const qty = parseFloat(item.quantity);
      await this._adjustProductStock(pId, qty);
    }

    // 2. የተከፈለውን ከፋይናንስ አካውንት መቀነስ
    const paid = parseFloat(receivingPayload.paid_amount || 0);
    if (paid > 0 && receivingPayload.account_id) {
      await this._adjustAccountBalance(parseInt(receivingPayload.account_id), -paid);
    }

    // 3. ያልተከፈለውን ዕዳ በአቅራቢው ላይ መደመር
    const unpaid = parseFloat(receivingPayload.grand_total) - paid;
    if (unpaid > 0 && receivingPayload.supplier_id) {
      await this._adjustContactBalance(parseInt(receivingPayload.supplier_id), unpaid);
    }

    // 4. በርክክብ ታሪክ ውስጥ መመዝገብ
    const offlineRecRecord = {
      id: 'local-rec-' + Date.now(),
      supplier_name: receivingPayload.supplier_name || 'አቅራቢ',
      supplier_id: receivingPayload.supplier_id,
      purchase_date: new Date().toISOString().replace('T', ' ').substring(0, 19),
      grand_total: parseFloat(receivingPayload.grand_total),
      paid_amount: paid,
      unpaid_balance: unpaid,
      payment_status: unpaid <= 0 ? 'paid' : (paid > 0 ? 'partial' : 'credit'),
      items: receivingPayload.items,
      is_offline: true,
      created_at: new Date().toISOString()
    };
    await this._putOne('purchases', offlineRecRecord);

    // 5. ዳሽቦርድ ላይ የቀኑን ግዢ እና የአቅራቢ ዕዳ ማዘመን
    await this._adjustDashboardMetrics({
      purchases: parseFloat(receivingPayload.grand_total),
      liquid: -paid,
      supplier_debt: unpaid
    });

    // 6. ወደ ወረፋ ማስገባት
    await this.enqueueTransaction('purchase', receivingPayload);

    return offlineRecRecord;
  },

  /**
   * ከመስመር ውጭ ገንዘብ መውጣትን በአካባቢው ማስተካከል
   */
  async applyOfflineWithdrawal(withdrawalPayload) {
    if (!this.instance) await this.init();

    const amount = parseFloat(withdrawalPayload.amount);

    // 1. ከአካውንቱ መቀነስ
    if (withdrawalPayload.account_id) {
      await this._adjustAccountBalance(parseInt(withdrawalPayload.account_id), -amount);
    }

    // 2. በገንዘብ መውጣት ታሪክ ውስጥ መመዝገብ
    const offlineWithRecord = {
      id: 'local-with-' + Date.now(),
      amount: amount,
      recipient_name: withdrawalPayload.recipient_name,
      reason: withdrawalPayload.reason,
      account_name: withdrawalPayload.account_name || 'ጥሬ ገንዘብ',
      withdrawal_date: new Date().toISOString().replace('T', ' ').substring(0, 19),
      is_offline: true,
      created_at: new Date().toISOString()
    };
    await this._putOne('withdrawals', offlineWithRecord);

    // 3. ዳሽቦርድ ማዘመን
    await this._adjustDashboardMetrics({
      withdrawals: amount,
      liquid: -amount
    });

    // 4. ወደ ወረፋ ማስገባት
    await this.enqueueTransaction('withdrawal', withdrawalPayload);

    return offlineWithRecord;
  },

  /**
   * ከመስመር ውጭ የስራ ማስኬጃ ወጪን በአካባቢው ማስተካከል
   */
  async applyOfflineExpense(expensePayload) {
    if (!this.instance) await this.init();

    const amount = parseFloat(expensePayload.amount);

    // 1. ከአካውንቱ መቀነስ
    if (expensePayload.account_id) {
      await this._adjustAccountBalance(parseInt(expensePayload.account_id), -amount);
    }

    // 2. በወጪ ታሪክ ውስጥ መመዝገብ
    const offlineExpRecord = {
      id: 'local-exp-' + Date.now(),
      amount: amount,
      category_name: expensePayload.category_name || 'የስራ ማስኬጃ',
      description: expensePayload.description,
      account_name: expensePayload.account_name || 'ጥሬ ገንዘብ',
      expense_date: new Date().toISOString().replace('T', ' ').substring(0, 19),
      is_offline: true,
      created_at: new Date().toISOString()
    };
    await this._putOne('expenses', offlineExpRecord);

    // 3. ዳሽቦርድ ማዘመን
    await this._adjustDashboardMetrics({
      expenses: amount,
      liquid: -amount
    });

    // 4. ወደ ወረፋ ማስገባት
    await this.enqueueTransaction('expense', expensePayload);

    return offlineExpRecord;
  },

  /**
   * ለአቅራቢ ከመስመር ውጭ ክፍያ መክፈል
   */
  async applyOfflineSupplierPayment(paymentPayload) {
    if (!this.instance) await this.init();

    const amount = parseFloat(paymentPayload.amount);

    // 1. ከአካውንቱ መቀነስ
    if (paymentPayload.account_id) {
      await this._adjustAccountBalance(parseInt(paymentPayload.account_id), -amount);
    }

    // 2. ከአቅራቢው ዕዳ መቀነስ
    if (paymentPayload.contact_id) {
      await this._adjustContactBalance(parseInt(paymentPayload.contact_id), -amount);
    }

    // 3. ዳሽቦርድ ላይ የአቅራቢ ዕዳ መቀነስ
    await this._adjustDashboardMetrics({
      supplier_debt: -amount,
      liquid: -amount
    });

    // 4. ወደ ወረፋ ማስገባት
    await this.enqueueTransaction('supplier_payment', paymentPayload);
  },

  async processLocalCustomerDebtPayment(paymentPayload) {
    const amount = parseFloat(paymentPayload.amount || 0);
    const contactId = parseInt(paymentPayload.contact_id || paymentPayload.customer_id);
    const accountId = parseInt(paymentPayload.account_id);

    // 1. ወደ አካውንቱ ገንዘብ መጨመር
    if (accountId) {
      await this._adjustAccountBalance(accountId, amount);
    }

    // 2. ከደንበኛው ዕዳ መቀነስ
    if (contactId) {
      await this._adjustContactBalance(contactId, -amount);
    }

    // 3. ዳሽቦርድ ላይ የደንበኛ ዕዳ መቀነስ እና ጥሬ ገንዘብ መጨመር
    await this._adjustDashboardMetrics({
      customer_debt: -amount,
      liquid: amount
    });

    // 4. ወደ ወረፋ ማስገባት
    await this.enqueueTransaction('customer_debt_settlement', paymentPayload);
  },

  async saveLocalCustomer(customerData) {
    if (!this.instance) await this.init();
    const id = customerData.id || ('cust-off-' + Date.now());
    customerData.id = id;
    customerData.type = 'customer';
    customerData.outstanding_balance = customerData.outstanding_balance || 0;
    customerData.is_active = 1;
    await this._putOne('contacts', customerData);
    await this.enqueueTransaction('create_customer', customerData);
    return customerData;
  },

  // -------------------------------------------------------------------------
  // የውስጥ ረዳት አሰራሮች (Internal Updaters)
  // -------------------------------------------------------------------------
  async _adjustProductStock(productId, delta) {
    const prod = await this._getOne('products', productId);
    if (prod) {
      prod.total_stock = Math.max(0, parseFloat((parseFloat(prod.total_stock || 0) + delta).toFixed(3)));
      await this._putOne('products', prod);
    }
  },

  async _adjustAccountBalance(accountId, delta) {
    const acc = await this._getOne('financial_accounts', accountId);
    if (acc) {
      acc.current_balance = parseFloat((parseFloat(acc.current_balance || 0) + delta).toFixed(2));
      await this._putOne('financial_accounts', acc);
    }
  },

  async _adjustContactBalance(contactId, delta) {
    const contact = await this._getOne('contacts', contactId);
    if (contact) {
      const curBal = parseFloat(contact.outstanding_balance !== undefined ? contact.outstanding_balance : (contact.current_balance || 0));
      contact.outstanding_balance = Math.max(0, parseFloat((curBal + delta).toFixed(2)));
      contact.current_balance = contact.outstanding_balance;
      await this._putOne('contacts', contact);
    }
  },

  async _adjustDashboardMetrics(changes) {
    const d = await this.getLocalDashboard('today');
    if (!d) return;

    if (changes.sales) d.operations.total_sales = (parseFloat(d.operations.total_sales || 0) + changes.sales).toFixed(2);
    if (changes.purchases) d.operations.total_purchases = (parseFloat(d.operations.total_purchases || 0) + changes.purchases).toFixed(2);
    if (changes.expenses) d.operations.total_expenses = (parseFloat(d.operations.total_expenses || 0) + changes.expenses).toFixed(2);
    if (changes.withdrawals) d.operations.total_withdrawals = (parseFloat(d.operations.total_withdrawals || 0) + changes.withdrawals).toFixed(2);
    if (changes.supplier_debt) d.debts.supplier_debt = (parseFloat(d.debts.supplier_debt || 0) + changes.supplier_debt).toFixed(2);
    if (changes.customer_debt) d.debts.customer_debt = (parseFloat(d.debts.customer_debt || 0) + changes.customer_debt).toFixed(2);
    if (changes.liquid) {
      d.actual_balances.total_liquid = (parseFloat(d.actual_balances.total_liquid || 0) + changes.liquid).toFixed(2);
      d.actual_balances.cash = (parseFloat(d.actual_balances.cash || 0) + changes.liquid).toFixed(2);
    }

    // ትርፍ ስሌት
    d.operations.gross_profit = (parseFloat(d.operations.total_sales || 0) * 0.35).toFixed(2); // ግምት
    d.operations.net_profit = (parseFloat(d.operations.gross_profit) - parseFloat(d.operations.total_expenses || 0)).toFixed(2);

    await this.cacheDashboard('today', d);
  }
};
