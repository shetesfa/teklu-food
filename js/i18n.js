/**
 * ተክሉ ጌታቸው ቢዝነስ - የሁለት ቋንቋ መዝገበ-ቃላት (Bilingual Dictionary)
 * አማርኛ (ነባሪ) እና እንግሊዝኛ
 */

const I18N = {
  currentLang: localStorage.getItem('teklu_lang') || 'am',

  translations: {
    am: {
      appName: 'ተክሉ ጌታቸው የከብት እርባታ እና የወተት ተዋጽኦ ማቀነባበሪያ',
      shortName: 'ተክሉ ቢዝነስ',
      currency: 'ETB',
      
      // ሁኔታዎች
      online: 'በመስመር ላይ (Online)',
      offline: 'ከመስመር ውጭ (Offline)',
      pendingSync: 'ያልተመሳሰሉ',
      syncNow: 'አሁን አመሳስል',
      syncSuccess: 'ሁሉም መረጃዎች በተሳካ ሁኔታ ተመሳስለዋል',
      
      // ዳሰሳ
      navDashboard: 'ዳሽቦርድ',
      navSales: 'ሽያጭ (POS)',
      navStock: 'ክምችት',
      navFinance: 'ፋይናንስ',
      navMore: 'ተጨማሪ',

      // ዳሽቦርድ
      todaySales: 'የዛሬ ሽያጭ',
      todayPurchases: 'የዛሬ ግዢ',
      todayExpenses: 'የዛሬ ወጪ',
      todayWithdrawals: 'የባለቤት መውጣት',
      cashBalance: 'የእጅ ጥሬ ገንዘብ',
      cbeBalance: 'ንግድ ባንክ (CBE)',
      telebirrBalance: 'ቴሌብር (Telebirr)',
      otherBanksBalance: 'ሌሎች ባንኮች',
      stockValuation: 'ጠቅላላ የክምችት ዋጋ',
      estimatedGrossProfit: 'የተገመተ ጠቅላላ ትርፍ',
      estimatedNetProfit: 'የተገመተ የተጣራ ትርፍ',
      lowStockAlert: 'ዝቅተኛ ክምችት ያላቸው እቃዎች',
      supplierDebt: 'ያልተከፈለ የአቅራቢ ዕዳ',
      customerDebt: 'ያልተሰበሰበ የደንበኛ ዕዳ',
      filterToday: 'ዛሬ',
      filterWeek: 'ይህ ሳምንት',
      filterMonth: 'ይህ ወር',

      // ሽያጭ / POS
      posTitle: 'የሬስቶራንት እና ምርት ሽያጭ',
      searchProduct: 'ምርት ይፈልጉ...',
      cart: 'የሽያጭ ቅርጫት',
      emptyCart: 'ምንም የተመረጠ ዕቃ የለም',
      subtotal: 'ንዑስ ድምር',
      discount: 'ቅናሽ',
      grandTotal: 'ጠቅላላ ድምር',
      paymentMethod: 'የክፍያ ዘዴ',
      payCash: 'ጥሬ ገንዘብ',
      payTelebirr: 'ቴሌብር',
      payCbe: 'ንግድ ባንክ',
      payMixed: 'ድብልቅ ክፍያ',
      payCredit: 'በብድር (Credit)',
      confirmSale: 'ሽያጩን አረጋግጥ',
      selectCustomer: 'ደንበኛ ይምረጡ',
      receipt: 'ደረሰኝ',
      itemsCount: 'የእቃዎች ብዛት',

      // ክምችት እና ምርቶች
      productsTitle: 'የምርቶች እና ክምችት መዝገብ',
      registerProduct: 'አዲስ ምርት መመዝገብ',
      productNameAm: 'የምርት ስም (አማርኛ)',
      productNameEn: 'የምርት ስም (እንግሊዝኛ)',
      category: 'ምድብ',
      unit: 'መለኪያ',
      sellingPrice: 'የመሸጫ ዋጋ',
      purchaseCost: 'የግዢ ወጪ',
      minStock: 'ዝቅተኛ ክምችት',
      source: 'ምንጭ',
      farmSource: 'የእርሻ ምርት',
      externalSource: 'የውጭ ግዢ',
      restaurantSource: 'የሬስቶራንት ምግብ',
      hasExpiry: 'የማብቂያ ቀን አለው',
      openingStock: 'የመክፈቻ ክምችት ማስገቢያ',
      adjustStock: 'የክምችት ማስተካከያ',
      stockLocation: 'መጋዘን',
      farmWarehouse: 'የእርሻ መጋዘን',
      restaurantStock: 'የሬስቶራንት ክምችት',
      mainStorage: 'ዋና ማከማቻ',
      availableQuantity: 'ያለ ክምችት',

      // ግዢ
      purchasesTitle: 'የውጭ ግዢዎች መዝገብ',
      recordPurchase: 'አዲስ ግዢ መመዝገብ',
      supplier: 'አቅራቢ',
      purchaseDate: 'የተገዛበት ቀን',
      unitPrice: 'የአንዱ ዋጋ',
      additionalCost: 'ተጨማሪ ወጪ (ትራንስፖርት)',
      paidAmount: 'የተከፈለ ገንዘብ',
      unpaidBalance: 'የቀረ ዕዳ',

      // ዝውውር
      transfersTitle: 'ከእርሻ ወደ ሬስቶራንት ዝውውር',
      sendTransfer: 'ምርት ወደ ሬስቶራንት ላክ',
      transferValue: 'የዝውውር ዋጋ',
      transferStatus: 'ሁኔታ',
      statusPending: 'በመጠባበቅ ላይ',
      statusReceived: 'ተቀባይነት አግኝቷል',
      statusRejected: 'ውድቅ ተደርጓል',
      receiveTransfer: 'ተቀበል',
      rejectTransfer: 'ውድቅ አድርግ',
      transferAccountingNote: 'ማስታወሻ፡ የውስጥ ዝውውር ለተጠቃለለው ድርጅት ውጫዊ ገቢ ወይም ወጪ ሆኖ አይቆጠርም!',

      // እርሻና ወተት
      farmTitle: 'የእርሻ እና ወተት ምርት ማቀነባበሪያ',
      recordProduction: 'አዲስ ምርት መመዝገብ',
      productionDate: 'የተመረተበት ቀን',
      expiryDate: 'የሚያበቃበት ቀን',
      batchNumber: 'የባች ቁጥር',
      productionCost: 'የማምረቻ ወጪ',

      // ወጪ እና መውጣት
      expensesTitle: 'የስራ ማስኬጃ ወጪዎች',
      recordExpense: 'አዲስ ወጪ መመዝገብ',
      expenseCategory: 'የወጪ ምድብ',
      department: 'መምሪያ',
      description: 'ማብራሪያ',
      paymentAccount: 'የተከፈለበት አካውንት',
      withdrawalsTitle: 'የባለቤት እና የተፈቀዱ መውጣቶች',
      recordWithdrawal: 'ገንዘብ መውጣት መመዝገብ',
      recipientName: 'ገንዘቡን የተቀበለው ሰው',
      withdrawalReason: 'የመውጫ ምክንያት',
      withdrawalNote: 'ማስታወሻ፡ የባለቤት መውጣት የስራ ማስኬጃ ወጪ ተደርጎ አይቆጠርም፤ የካሽ/ባንክ ሚዛንን ብቻ ነው የሚቀንሰው።',

      // ደንበኞችና አቅራቢዎች
      contactsTitle: 'ደንበኞች እና አቅራቢዎች',
      addContact: 'አዲስ እውቂያ መመዝገብ',
      contactName: 'ስም',
      contactPhone: 'ስልክ ቁጥር',
      contactAddress: 'አድራሻ',
      outstandingDebt: 'ያለበት ዕዳ',
      settleDebt: 'ዕዳ ማስታረቅ/መክፈል',

      // የምግብ አዘገጃጀት
      recipesTitle: 'የምግብ አዘገጃጀት እና ግብዓቶች (Recipes)',
      addRecipe: 'አዲስ ግብዓት አገናኝ',
      parentMeal: 'የሬስቶራንት ምግብ',
      rawIngredient: 'ጥሬ ግብዓት',
      quantityNeeded: 'አስፈላጊ መጠን',

      // ዕለታዊ ሂሳብ መዝጊያ
      dailyClosingTitle: 'ዕለታዊ ሂሳብ መዝጊያ',
      systemCash: 'በሲስተም ያለ ጥሬ ገንዘብ',
      actualCash: 'በእጅ ያለ ትክክለኛ ጥሬ ገንዘብ',
      cashDifference: 'የገንዘብ ልዩነት',
      systemCbe: 'በሲስተም ያለ CBE',
      actualCbe: 'በባንክ ያለ ትክክለኛ CBE',
      systemTelebirr: 'በሲስተም ያለ ቴሌብር',
      actualTelebirr: 'በቴሌብር ያለ ትክክለኛ ሂሳብ',
      discrepancyReason: 'የልዩነት ምክንያት',
      closeDay: 'የዕለቱን ሂሳብ ዝጋ',
      reopenDay: 'የተዘጋውን ሂሳብ ዳግም ክፈት',
      reopenReason: 'ዳግም የመክፈቻ ምክንያት',
      dayClosedSuccess: 'የዕለቱ ሂሳብ በተሳካ ሁኔታ ተዘግቷል',

      // ሪፖርቶች
      reportsTitle: 'የፋይናንስና የትርፍ ሪፖርቶች',
      reportType: 'የሪፖርት ዓይነት',
      plStatement: 'የትርፍና ኪሳራ መግለጫ (P&L)',
      productProfitability: 'የምርቶች አትራፊነት',
      cashFlow: 'የጥሬ ገንዘብ ፍሰት',
      grossRevenue: 'ጠቅላላ የሽያጭ ገቢ',
      cogs: 'የተሸጡ ዕቃዎች ወጪ (COGS)',
      grossProfit: 'ጠቅላላ ትርፍ (Gross Profit)',
      operatingExpenses: 'የስራ ማስኬጃ ወጪዎች',
      netProfit: 'የተጣራ ትርፍ (Net Profit)',
      ownerWithdrawals: 'የባለቤት መውጣት',

      // ተጠቃሚዎች እና መግቢያ
      loginTitle: 'ወደ ሲስተሙ ይግቡ',
      username: 'የተጠቃሚ ስም',
      password: 'የይለፍ ቃል',
      loginBtn: 'ግባ (Login)',
      logout: 'ውጣ (Logout)',
      superAdmin: 'ዋና አስተዳዳሪ',
      manager: 'ስራ አስኪያጅ',
      usersTitle: 'የተጠቃሚዎች ቁጥጥር',
      addUser: 'አዲስ ተጠቃሚ ፍጠር',
      fullName: 'ሙሉ ስም',
      role: 'ሚና',
      permissions: 'የተፈቀዱ ስራዎች',
      canDiscount: 'የዋጋ ቅናሽ መስጠት',
      canAdjustStock: 'ክምችት ማስተካከል',
      canRecordWithdrawal: 'ገንዘብ ማውጣት መመዝገብ',
      canViewPl: 'የትርፍና ኪሳራ ሪፖርት ማየት',
      backupTitle: 'የመረጃ ምትኬ (Backup & Export)',
      exportBackup: 'ዳታ ወደ ውጭ ላክ (Download Backup)',

      // አጠቃላይ
      save: 'መዝግብ',
      cancel: 'ሰርዝ',
      close: 'ዝጋ',
      edit: 'አስተካክል',
      delete: 'አጥፋ',
      actions: 'እርምጃዎች',
      loading: 'በመጫን ላይ...',
      success: 'ተሳክቷል',
      error: 'ስህተት'
    },

    en: {
      appName: 'Teklu Getachew Cattle Breeding & Dairy Processing Enterprise',
      shortName: 'Teklu Enterprise',
      currency: 'ETB',

      // Status
      online: 'Online',
      offline: 'Offline',
      pendingSync: 'Pending Sync',
      syncNow: 'Sync Now',
      syncSuccess: 'All data synchronized successfully',

      // Navigation
      navDashboard: 'Dashboard',
      navSales: 'POS & Sales',
      navStock: 'Inventory',
      navFinance: 'Finance',
      navMore: 'More',

      // Dashboard
      todaySales: "Today's Sales",
      todayPurchases: "Today's Purchases",
      todayExpenses: "Today's Expenses",
      todayWithdrawals: "Today's Withdrawals",
      cashBalance: 'Cash in Hand',
      cbeBalance: 'CBE Bank',
      telebirrBalance: 'Telebirr',
      otherBanksBalance: 'Other Banks',
      stockValuation: 'Total Stock Value',
      estimatedGrossProfit: 'Est. Gross Profit',
      estimatedNetProfit: 'Est. Net Profit',
      lowStockAlert: 'Low Stock Products',
      supplierDebt: 'Supplier Debt',
      customerDebt: 'Customer Receivables',
      filterToday: 'Today',
      filterWeek: 'This Week',
      filterMonth: 'This Month',

      // POS & Sales
      posTitle: 'Restaurant & Product POS',
      searchProduct: 'Search products...',
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
      payCredit: 'On Credit',
      confirmSale: 'Confirm Sale',
      selectCustomer: 'Select Customer',
      receipt: 'Receipt',
      itemsCount: 'Items Count',

      // Inventory & Products
      productsTitle: 'Products & Inventory',
      registerProduct: 'Register New Product',
      productNameAm: 'Name (Amharic)',
      productNameEn: 'Name (English)',
      category: 'Category',
      unit: 'Unit',
      sellingPrice: 'Selling Price',
      purchaseCost: 'Purchase Cost',
      minStock: 'Min Stock Level',
      source: 'Source',
      farmSource: 'Farm Produce',
      externalSource: 'External Purchase',
      restaurantSource: 'Restaurant Meal',
      hasExpiry: 'Has Expiry Date',
      openingStock: 'Opening Stock Entry',
      adjustStock: 'Stock Adjustment',
      stockLocation: 'Warehouse Location',
      farmWarehouse: 'Farm Warehouse',
      restaurantStock: 'Restaurant Stock',
      mainStorage: 'Main Storage',
      availableQuantity: 'In Stock',

      // Purchases
      purchasesTitle: 'External Purchases',
      recordPurchase: 'Record Purchase',
      supplier: 'Supplier',
      purchaseDate: 'Purchase Date',
      unitPrice: 'Unit Price',
      additionalCost: 'Additional Cost (Transport)',
      paidAmount: 'Amount Paid',
      unpaidBalance: 'Unpaid Balance',

      // Transfers
      transfersTitle: 'Farm-to-Restaurant Transfers',
      sendTransfer: 'Send to Restaurant',
      transferValue: 'Transfer Value',
      transferStatus: 'Status',
      statusPending: 'Pending',
      statusReceived: 'Received',
      statusRejected: 'Rejected',
      receiveTransfer: 'Receive',
      rejectTransfer: 'Reject',
      transferAccountingNote: 'Accounting Note: Internal transfers are NOT external income or expenses for the consolidated enterprise.',

      // Farm & Dairy
      farmTitle: 'Farm & Dairy Production',
      recordProduction: 'Record Production',
      productionDate: 'Production Date',
      expiryDate: 'Expiry Date',
      batchNumber: 'Batch Number',
      productionCost: 'Production Cost',

      // Expenses & Withdrawals
      expensesTitle: 'Operating Expenses',
      recordExpense: 'Record Expense',
      expenseCategory: 'Expense Category',
      department: 'Department',
      description: 'Description',
      paymentAccount: 'Payment Account',
      withdrawalsTitle: 'Owner & Authorized Withdrawals',
      recordWithdrawal: 'Record Withdrawal',
      recipientName: 'Recipient Name',
      withdrawalReason: 'Reason',
      withdrawalNote: 'Note: Owner withdrawals reduce cash/bank balance but are NOT operating expenses.',

      // Contacts
      contactsTitle: 'Customers & Suppliers',
      addContact: 'Add Contact',
      contactName: 'Name',
      contactPhone: 'Phone',
      contactAddress: 'Address',
      outstandingDebt: 'Outstanding Balance',
      settleDebt: 'Settle Debt / Payment',

      // Recipes
      recipesTitle: 'Recipes & Ingredients',
      addRecipe: 'Connect Ingredient',
      parentMeal: 'Restaurant Meal',
      rawIngredient: 'Raw Ingredient',
      quantityNeeded: 'Qty Needed',

      // Daily Closing
      dailyClosingTitle: 'Daily Closing',
      systemCash: 'System Cash',
      actualCash: 'Physical Cash',
      cashDifference: 'Cash Difference',
      systemCbe: 'System CBE',
      actualCbe: 'Actual CBE',
      systemTelebirr: 'System Telebirr',
      actualTelebirr: 'Actual Telebirr',
      discrepancyReason: 'Discrepancy Reason',
      closeDay: 'Close Day',
      reopenDay: 'Reopen Closed Day',
      reopenReason: 'Reopening Reason',
      dayClosedSuccess: 'Day successfully closed',

      // Reports
      reportsTitle: 'Financial & Profit Reports',
      reportType: 'Report Type',
      plStatement: 'Profit & Loss Statement (P&L)',
      productProfitability: 'Product Profitability',
      cashFlow: 'Cash Flow',
      grossRevenue: 'Gross Revenue',
      cogs: 'Cost of Goods Sold (COGS)',
      grossProfit: 'Gross Profit',
      operatingExpenses: 'Operating Expenses',
      netProfit: 'Net Profit',
      ownerWithdrawals: 'Owner Withdrawals',

      // Users & Auth
      loginTitle: 'Sign In',
      username: 'Username',
      password: 'Password',
      loginBtn: 'Sign In',
      logout: 'Logout',
      superAdmin: 'Super Admin',
      manager: 'Manager',
      usersTitle: 'User Management',
      addUser: 'Create User',
      fullName: 'Full Name',
      role: 'Role',
      permissions: 'Permissions',
      canDiscount: 'Allow Discount',
      canAdjustStock: 'Allow Stock Adjust',
      canRecordWithdrawal: 'Allow Withdrawal',
      canViewPl: 'Allow View P&L',
      backupTitle: 'Backup & Export',
      exportBackup: 'Download Backup',

      // Common
      save: 'Save',
      cancel: 'Cancel',
      close: 'Close',
      edit: 'Edit',
      delete: 'Delete',
      actions: 'Actions',
      loading: 'Loading...',
      success: 'Success',
      error: 'Error'
    }
  },

  t(key) {
    const lang = this.currentLang;
    if (this.translations[lang] && this.translations[lang][key]) {
      return this.translations[lang][key];
    }
    if (this.translations['en'][key]) {
      return this.translations['en'][key];
    }
    return key;
  },

  setLang(lang) {
    if (this.translations[lang]) {
      this.currentLang = lang;
      localStorage.setItem('teklu_lang', lang);
      document.documentElement.lang = lang;
      this.applyTranslations();
    }
  },

  toggleLang() {
    this.setLang(this.currentLang === 'am' ? 'en' : 'am');
  },

  applyTranslations() {
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      el.textContent = this.t(key);
    });

    document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
      const key = el.getAttribute('data-i18n-placeholder');
      el.setAttribute('placeholder', this.t(key));
    });

    const langToggleBtn = document.getElementById('btn-lang-toggle');
    if (langToggleBtn) {
      langToggleBtn.textContent = this.currentLang === 'am' ? 'EN' : 'አማ';
    }
  }
};
