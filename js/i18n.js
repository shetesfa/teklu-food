/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * የቋንቋ መዝገበ-ቃላት (Bilingual Dictionary)
 */

const I18N = {
  currentLang: localStorage.getItem('teklu_lang') || 'am',

  translations: {
    am: {
      appName: 'ተክሉ ምግብ ቤት',
      shortName: 'ተክሉ ምግብ ቤት',
      currency: 'ETB',
      
      // ሁኔታዎች
      online: 'በመስመር ላይ',
      offline: 'ከመስመር ውጭ',
      pendingSync: 'ያልተመሳሰሉ',
      syncNow: 'አሁን አመሳስል',
      syncSuccess: 'ሁሉም መረጃዎች በተሳካ ሁኔታ ተመሳስለዋል',
      
      // ዋና ዳሰሳ (Navigation)
      navDashboard: 'ዳሽቦርድ',
      navSales: 'ሽያጭ (POS)',
      navReceiving: 'እቃ መረከቢያ',
      navFoodRecipe: 'የምግብ ዝርዝር',
      navStock: 'ክምችት',
      navCustomerDebts: 'የደንበኛ ዱቤ',
      navWithdrawals: 'ገንዘብ መውጣት',
      navExpenses: 'ወጪዎች',
      navSuppliers: 'አቅራቢዎች',
      navMore: 'ተጨማሪ',

      // ዳሽቦርድ (Dashboard)
      dashboardTitle: 'የንግድ ስራ ማጠቃለያ ዳሽቦርድ',
      todaySales: 'ሽያጭ',
      todayPurchases: 'ግዢ',
      todayExpenses: 'የስራ ማስኬጃ ወጪ',
      todayWithdrawals: 'የወጣ ገንዘብ (መውጣት)',
      cashBalance: 'የእጅ ጥሬ ገንዘብ',
      cbeBalance: 'ንግድ ባንክ (CBE)',
      telebirrBalance: 'ቴሌብር (Telebirr)',
      otherBanksBalance: 'ሌሎች ባንኮች',
      totalLiquid: 'በእጅና በባንክ ያለ ጠቅላላ ገንዘብ',
      financialAccountsBalance: 'በእጅና በባንክ ያሉ የገንዘብ ሂሳቦች',
      stockValuation: 'ጠቅላላ የክምችት ዋጋ',
      estimatedGrossProfit: 'ጠቅላላ ትርፍ (የምግብ ማዘጋጃ ወጪ ተቀንሶ)',
      estimatedNetProfit: 'የተጣራ ትርፍ (ሁሉም ወጪዎች ተቀንሰው)',
      lowStockAlert: 'ዝቅተኛ ክምችት ማስጠንቀቂያ',
      supplierDebt: 'ለአቅራቢ የሚከፈል ዕዳ',
      customerDebt: 'ከደንበኛ የሚሰበሰብ ዱቤ',
      filterToday: 'ዛሬ',
      filterYesterday: 'ትናንት',
      filterWeek: 'ይህ ሳምንት',
      filterMonth: 'ይህ ወር',
      filterLastMonth: 'ያለፈው ወር',
      filterCustom: 'የቀን ገደብ',

      // ሽያጭ / POS
      posTitle: 'የምግብ እና ምርቶች ሽያጭ (POS)',
      searchProduct: 'ምግብ ፈልግ...',
      cart: 'የሽያጭ ቅርጫት',
      emptyCart: 'ምንም የተመረጠ ምግብ የለም',
      subtotal: 'ንዑስ ድምር',
      discount: 'ቅናሽ',
      grandTotal: 'ጠቅላላ ድምር',
      paymentMethod: 'የክፍያ ዘዴ',
      payCash: 'ጥሬ ገንዘብ (Cash)',
      payTelebirr: 'ቴሌብር (Telebirr)',
      payCbe: 'ንግድ ባንክ (CBE)',
      payMixed: 'ድብልቅ ክፍያ (Mixed)',
      payCredit: 'በብድር (Credit)',
      confirmSale: 'ሽያጩን አረጋግጥ',
      selectCustomer: 'ደንበኛ ይምረጡ',
      registerNewCustomer: '+ አዲስ ደንበኛ መዝግብ',
      receipt: 'ደረሰኝ',
      itemsCount: 'የእቃዎች ብዛት',

      // የደንበኛ ዱቤ (Customer Credit & Debts)
      customerDebtTitle: 'የደንበኞች ዱቤ እና የብድር መቆጣጠሪያ',
      registerCustomer: '+ አዲስ ደንበኛ መዝግብ',
      unpaidDebt: 'ቀሪ ያልተከፈለ ዕዳ',
      totalCreditGiven: 'ጠቅላላ የተበደረው',
      totalDebtPaid: 'የተከፈለ ዕዳ',
      settleDebt: 'ዕዳ መቀበያ',
      debtReceipt: 'የዱቤ ደረሰኝ',
      paymentReceipt: 'የክፍያ ደረሰኝ',
      orderedDishes: 'የተወሰዱ ምግቦች ዝርዝር',
      paymentHistory: 'የክፍያ ታሪክ',

      // እቃ መረከቢያ (Goods Receiving)
      receivingTitle: 'እቃ መረከቢያ (Goods Receiving)',
      receiveNewGoods: '+ አዲስ እቃ ተረከብ',
      selectSupplier: 'አቅራቢ ይምረጡ',
      orRegisterSupplier: 'ወይም አዲስ አቅራቢ መዝግብ',
      supplierName: 'የአቅራቢ/ኩባንያ ስም',
      supplierPhone: 'ስልክ ቁጥር',
      supplierAddress: 'አድራሻ',
      supplierNotes: 'ማስታወሻ',
      receivedProducts: 'የተረከቧቸው ምርቶች',
      selectProduct: 'ምርት ይምረጡ',
      quantity: 'መጠን',
      unitPrice: 'የአንዱ ዋጋ',
      lineTotal: 'ድምር ዋጋ',
      addProductRow: '+ ሌላ እቃ ጨምር',
      totalPurchaseAmount: 'ጠቅላላ የግዢ ዋጋ',
      paidAmountImmediately: 'ወዲያውኑ የተከፈለ ገንዘብ',
      unpaidBalanceDebt: 'ያልተከፈለ ዕዳ (ለአቅራቢ የሚገባ)',
      paymentAccount: 'የተከፈለበት አካውንት',
      saveReceiving: 'እቃውን ተረከብ',

      // የአቅራቢ ሂሳብ ዝርዝር (Supplier Statement & Ledger)
      supplierAccountDetails: 'የአቅራቢ ሂሳብ ዝርዝር እና ታሪክ',
      totalGoodsReceived: 'ጠቅላላ የተረከቡት እቃዎች',
      totalPurchasesAmount: 'ጠቅላላ የግዢ መጠን',
      totalPaymentsMade: 'ጠቅላላ የተከፈለው',
      outstandingBalance: 'ያልተከፈለ ቀሪ ዕዳ',
      makePayment: '💳 ክፍያ ፈጽም (Make Payment)',
      allTransactions: 'ሁሉም ግብይቶች',
      filterPaid: 'ሙሉ የተከፈሉ',
      filterPartial: 'በከፊል የተከፈሉ',
      filterUnpaid: 'ያልተከፈሉ',
      receivingHistory: 'የእቃ ርክክቦች ታሪክ',
      paymentHistory: 'የክፍያዎች ታሪክ',
      recordSupplierPayment: 'ለአቅራቢ ክፍያ መመዝገብ',
      amountToPay: 'የሚከፈለው የገንዘብ መጠን',

      // የምግብ ዝርዝር እና ማዘጋጃ (Food Dishes & Preparation)
      foodRecipeTitle: 'የምግብ ዝርዝር እና ማዘጋጃ',
      createNewRecipe: '+ አዲስ ምግብ መዝግብ',
      foodNameAm: 'የምግብ ስም',
      foodNameEn: 'የምግብ ስም (እንግሊዝኛ - አማራጭ)',
      sellingPrice: 'የመሸጫ ዋጋ',
      recipeIngredients: 'የሚያስፈልጉ ጥሬ ግብዓቶች (ከተመዘገበው ክምችት)',
      addIngredientRow: '+ ግብዓት ጨምር',
      costPerServing: 'የአንድ ሰሃን ማዘጋጃ ወጪ',
      estimatedProfit: 'የሚጠበቅ ትርፍ (በሰሃን)',
      availableServings: 'በክምችት ማዘጋጀት የሚቻል (ሰሃን)',
      saveRecipeBtn: 'ምግቡን መዝግብ',

      // ክምችት እና ምርቶች (Inventory & Products)
      productsTitle: 'የምርቶች እና ጥሬ ዕቃዎች መዝገብ',
      registerProduct: '+ አዲስ ምርት / ጥሬ ዕቃ',
      productNameAm: 'የምርት ስም',
      productNameEn: 'የምርት ስም (እንግሊዝኛ - አማራጭ)',
      category: 'ምድብ',
      unit: 'መለኪያ',
      purchaseCost: 'የአንዱ ግዢ ዋጋ (ETB)',
      minStock: 'ዝቅተኛ ክምችት ማስጠንቀቂያ መጠን',
      currentStock: 'አሁን ያለ ክምችት',
      adjustStock: 'ክምችት ማስተካከያ',
      stockHistory: 'የክምችት ታሪክ',

      // ገንዘብ መውጣት (Withdrawals)
      withdrawalsTitle: 'ገንዘብ መውጣት (Withdrawals)',
      recordWithdrawal: '+ ገንዘብ መውጣት መዝግብ',
      recipientName: 'ተቀባይ ሰው',
      withdrawalReason: 'ምክንያት',
      withdrawalNote: 'ማስታወሻ፡ ገንዘብ መውጣት (የባለቤት ድርሻ) የስራ ማስኬጃ ወጪ ተደርጎ አይቆጠርም፤ በእጅና በባንክ ያለውን ገንዘብ ብቻ ይቀንሳል።',
      withdrawalAccountLabel: 'ገንዘቡ የወጣበት ሂሳብ (ጥሬ ገንዘብ፣ ቴሌብር፣ ንግድ ባንክ)',

      // የስራ ማስኬጃ ወጪ (Expenses)
      expensesTitle: 'የስራ ማስኬጃ ወጪዎች (Operating Expenses)',
      recordExpense: '+ ወጪ መዝግብ',
      expenseCategory: 'የወጪ ምድብ',
      description: 'ማብራሪያ',

      // አጠቃላይ
      save: 'መዝግብ',
      cancel: 'ሰርዝ',
      close: 'ዝጋ',
      search: 'ፈልግ...',
      actions: 'እርምጃዎች',
      date: 'ቀን',
      time: 'ሰዓት',
      status: 'ሁኔታ',
      loginBtn: 'ግባ (Login)',
      logout: 'ውጣ'
    },

    en: {
      appName: 'Teklu Restaurant',
      shortName: 'Teklu Restaurant',
      currency: 'ETB',
      
      // Status
      online: 'Online',
      offline: 'Offline',
      pendingSync: 'Pending',
      syncNow: 'Sync Now',
      syncSuccess: 'All data synchronized successfully',
      
      // Navigation
      navDashboard: 'Dashboard',
      navSales: 'Sales (POS)',
      navReceiving: 'Receiving',
      navFoodRecipe: 'Food Menu',
      navStock: 'Inventory',
      navCustomerDebts: 'Customer Debts',
      navWithdrawals: 'Withdrawals',
      navExpenses: 'Expenses',
      navSuppliers: 'Suppliers',
      navMore: 'More',

      // Dashboard
      dashboardTitle: 'Business Overview Dashboard',
      todaySales: 'Sales',
      todayPurchases: 'Purchases',
      todayExpenses: 'Operating Expenses',
      todayWithdrawals: 'Withdrawals',
      cashBalance: 'Cash in Hand',
      cbeBalance: 'CBE Bank',
      telebirrBalance: 'Telebirr',
      otherBanksBalance: 'Other Banks',
      totalLiquid: 'Total Liquid Cash & Bank',
      financialAccountsBalance: 'Cash & Bank Balances',
      stockValuation: 'Total Stock Valuation',
      estimatedGrossProfit: 'Gross Profit (After Food Cost)',
      estimatedNetProfit: 'Net Profit (After All Expenses)',
      lowStockAlert: 'Low Stock Alert',
      supplierDebt: 'Supplier Payable Debt',
      customerDebt: 'Customer Receivable Debt',
      filterToday: 'Today',
      filterYesterday: 'Yesterday',
      filterWeek: 'This Week',
      filterMonth: 'This Month',
      filterLastMonth: 'Last Month',
      filterCustom: 'Date Range',

      // Sales / POS
      posTitle: 'Food & Products Point of Sale (POS)',
      searchProduct: 'Search food...',
      cart: 'Sales Cart',
      emptyCart: 'Cart is empty',
      subtotal: 'Subtotal',
      discount: 'Discount',
      grandTotal: 'Grand Total',
      paymentMethod: 'Payment Method',
      payCash: 'Cash',
      payTelebirr: 'Telebirr',
      payCbe: 'CBE Bank',
      payMixed: 'Mixed Payment',
      payCredit: 'Credit Sale',
      confirmSale: 'Confirm Sale',
      selectCustomer: 'Select Customer',
      registerNewCustomer: '+ Register New Customer',
      receipt: 'Receipt',
      itemsCount: 'Items Count',

      // Customer Debts
      customerDebtTitle: 'Customer Credit & Debts Control',
      registerCustomer: '+ Register New Customer',
      unpaidDebt: 'Unpaid Remaining Debt',
      totalCreditGiven: 'Total Borrowed',
      totalDebtPaid: 'Total Paid',
      settleDebt: 'Collect Debt Payment',
      debtReceipt: 'Credit Order Receipt',
      paymentReceipt: 'Payment Voucher',
      orderedDishes: 'Ordered Dishes History',
      paymentHistory: 'Payment History',
      receipt: 'Receipt',
      itemsCount: 'Items Count',

      // Goods Receiving
      receivingTitle: 'Goods Receiving (እቃ መረከቢያ)',
      receiveNewGoods: '+ Receive Goods',
      selectSupplier: 'Select Supplier',
      orRegisterSupplier: 'Or Register New Supplier',
      supplierName: 'Supplier / Company Name',
      supplierPhone: 'Phone Number',
      supplierAddress: 'Address',
      supplierNotes: 'Notes',
      receivedProducts: 'Received Products',
      selectProduct: 'Select Product',
      quantity: 'Quantity',
      unitPrice: 'Unit Price',
      lineTotal: 'Line Total',
      addProductRow: '+ Add Product Line',
      totalPurchaseAmount: 'Total Purchase Amount',
      paidAmountImmediately: 'Paid Immediately',
      unpaidBalanceDebt: 'Outstanding Balance (Payable)',
      paymentAccount: 'Payment Account',
      saveReceiving: 'Save Goods Receipt',

      // Supplier Account Details & Ledger
      supplierAccountDetails: 'Supplier Account Details & History',
      totalGoodsReceived: 'Total Goods Received',
      totalPurchasesAmount: 'Total Purchases Amount',
      totalPaymentsMade: 'Total Payments Made',
      outstandingBalance: 'Outstanding Balance',
      makePayment: '💳 Make Payment',
      allTransactions: 'All Transactions',
      filterPaid: 'Paid',
      filterPartial: 'Partially Paid',
      filterUnpaid: 'Unpaid',
      receivingHistory: 'Goods Receiving History',
      paymentHistory: 'Payment History',
      recordSupplierPayment: 'Record Payment to Supplier',
      amountToPay: 'Amount to Pay',

      // Food Creation & Recipes
      foodRecipeTitle: 'Food Menu & Preparation',
      createNewRecipe: '+ Add New Dish',
      foodNameAm: 'Dish Name (Amharic)',
      foodNameEn: 'Dish Name (English - Optional)',
      sellingPrice: 'Selling Price',
      recipeIngredients: 'Required Ingredients (from stock)',
      addIngredientRow: '+ Add Ingredient',
      costPerServing: 'Cost per Plate',
      estimatedProfit: 'Est. Profit per Plate',
      availableServings: 'Available Plates in Stock',
      saveRecipeBtn: 'Save Food Dish',

      // Inventory & Products
      productsTitle: 'Products & Raw Materials Inventory',
      registerProduct: '+ Register Raw Material / Item',
      productNameAm: 'Item Name',
      productNameEn: 'Item Name (English - Optional)',
      category: 'Category',
      unit: 'Unit',
      purchaseCost: 'Unit Purchase Cost',
      minStock: 'Min Stock Alert Level',
      currentStock: 'Current Stock',
      adjustStock: 'Adjust Stock',
      stockHistory: 'Stock History',

      // Withdrawals
      withdrawalsTitle: 'Withdrawals (ገንዘብ መውጣት)',
      recordWithdrawal: '+ Record Withdrawal',
      recipientName: 'Recipient Name',
      withdrawalReason: 'Reason',
      withdrawalNote: 'Note: Withdrawals (Owner draw) are separate from operating expenses; they only reduce cash/bank balance.',
      withdrawalAccountLabel: 'Account Withdrawn From (Cash, Telebirr, Bank)',

      // Operating Expenses
      expensesTitle: 'Operating Expenses',
      recordExpense: '+ Record Expense',
      expenseCategory: 'Expense Category',
      description: 'Description',

      // General
      save: 'Save',
      cancel: 'Cancel',
      close: 'Close',
      search: 'Search...',
      actions: 'Actions',
      date: 'Date',
      time: 'Time',
      status: 'Status',
      loginBtn: 'Login',
      logout: 'Logout'
    }
  },

  t(key) {
    const lang = this.currentLang;
    if (this.translations[lang] && this.translations[lang][key]) {
      return this.translations[lang][key];
    }
    return this.translations['am'][key] || key;
  },

  setLang(lang) {
    if (lang !== 'am' && lang !== 'en') return;
    this.currentLang = lang;
    localStorage.setItem('teklu_lang', lang);
    this.applyTranslations();
  },

  toggleLang() {
    this.setLang(this.currentLang === 'am' ? 'en' : 'am');
  },

  applyTranslations() {
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      el.textContent = this.t(key);
    });

    document.querySelectorAll('[data-i18n-ph]').forEach((el) => {
      const key = el.getAttribute('data-i18n-ph');
      el.setAttribute('placeholder', this.t(key));
    });

    const langBtn = document.getElementById('btn-lang-toggle');
    if (langBtn) {
      langBtn.textContent = this.currentLang === 'am' ? 'EN' : 'አማ';
    }

    // የገጹን ርዕስ ማስተካከል
    document.title = this.t('appName');
  }
};
