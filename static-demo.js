/**
 * Browser-only sample data for the static demo build.
 * The sample records are fictional and persist only in this browser.
 */
(() => {
  const host = window.location.hostname.toLowerCase();
  const path = window.location.pathname.toLowerCase();
  const isGitHubPages = host.endsWith('.github.io') && path.includes('/smartstore');
  const requestedDemo = new URLSearchParams(window.location.search).get('demo') === '1';
  window.SMARTSTORE_DEMO_MODE = isGitHubPages || requestedDemo;

  const storageKey = 'smartstore_static_demo_v1';
  const daysAgo = (days) => {
    const date = new Date();
    date.setDate(date.getDate() - days);
    return date.toISOString().slice(0, 10);
  };
  const nowText = () => new Date().toLocaleString('en-IN', { hour12: false });

  const seed = {
    products: [
      { id: 1, name: 'Wireless Ergonomic Mouse', sku: 'MS-204', barcode: '8901000000204', category: 'Electronics', cost_price: 520, price: 899, stock: 7, min: 8, unit: 'pcs' },
      { id: 2, name: 'Mechanical Keyboard RGB', sku: 'KB-101', barcode: '8901000000101', category: 'Electronics', cost_price: 1850, price: 2799, stock: 18, min: 6, unit: 'pcs' },
      { id: 3, name: 'USB-C Fast Charger 30W', sku: 'CH-030', barcode: '8901000000030', category: 'Electronics', cost_price: 490, price: 799, stock: 4, min: 10, unit: 'pcs' },
      { id: 4, name: 'Braided USB-C Cable 2m', sku: 'CB-202', barcode: '8901000000202', category: 'Accessories', cost_price: 160, price: 349, stock: 42, min: 12, unit: 'pcs' },
      { id: 5, name: 'Aluminium Laptop Stand', sku: 'LS-115', barcode: '8901000000115', category: 'Accessories', cost_price: 680, price: 1199, stock: 13, min: 5, unit: 'pcs' },
      { id: 6, name: '1080p USB Webcam', sku: 'WC-108', barcode: '8901000000108', category: 'Electronics', cost_price: 1250, price: 1899, stock: 0, min: 4, unit: 'pcs' },
      { id: 7, name: 'Noise-Isolating Headphones', sku: 'HP-410', barcode: '8901000000410', category: 'Electronics', cost_price: 2100, price: 3299, stock: 9, min: 5, unit: 'pcs' },
      { id: 8, name: 'A5 Hardcover Notebook', sku: 'NB-505', barcode: '8901000000505', category: 'Stationery', cost_price: 75, price: 149, stock: 55, min: 15, unit: 'pcs' },
      { id: 9, name: 'Stainless Steel Thermal Flask', sku: 'FL-100', barcode: '8901000000100', category: 'Lifestyle', cost_price: 390, price: 699, stock: 3, min: 8, unit: 'pcs' },
      { id: 10, name: 'LED Desk Lamp', sku: 'DL-220', barcode: '8901000000220', category: 'Home', cost_price: 920, price: 1499, stock: 16, min: 5, unit: 'pcs' },
      { id: 11, name: 'Wireless Presenter', sku: 'PR-700', barcode: '8901000000700', category: 'Accessories', cost_price: 560, price: 999, stock: 11, min: 4, unit: 'pcs' },
      { id: 12, name: 'Portable SSD 1TB', sku: 'SD-100', barcode: '8901000001001', category: 'Electronics', cost_price: 4650, price: 6299, stock: 6, min: 3, unit: 'pcs' },
      { id: 13, name: 'Ceramic Coffee Mug', sku: 'MG-330', barcode: '8901000000330', category: 'Lifestyle', cost_price: 140, price: 299, stock: 28, min: 10, unit: 'pcs' },
      { id: 14, name: 'Wireless Earbuds', sku: 'EB-808', barcode: '8901000000808', category: 'Electronics', cost_price: 1750, price: 2699, stock: 2, min: 6, unit: 'pcs' }
    ],
    categories: [
      { id: 1, name: 'Accessories' }, { id: 2, name: 'Electronics' },
      { id: 3, name: 'Home' }, { id: 4, name: 'Lifestyle' }, { id: 5, name: 'Stationery' }
    ],
    customers: [
      { id: 1, name: 'Aarav Sharma', phone: '9800001001', email: 'aarav@example.test', address: 'Lalitpur', purchases: 2, total_spent: 4898 },
      { id: 2, name: 'Maya Gurung', phone: '9800001002', email: 'maya@example.test', address: 'Kathmandu', purchases: 1, total_spent: 2098 },
      { id: 3, name: 'Rohan Thapa', phone: '9800001003', email: 'rohan@example.test', address: 'Bhaktapur', purchases: 1, total_spent: 3463 },
      { id: 4, name: 'Sita Karki', phone: '9800001004', email: 'sita@example.test', address: 'Patan', purchases: 1, total_spent: 1549 },
      { id: 5, name: 'Nabin Rai', phone: '9800001005', email: 'nabin@example.test', address: 'Kathmandu', purchases: 1, total_spent: 3148 }
    ],
    suppliers: [
      { id: 1, name: 'Anil Joshi', company: 'Everest Tech Supplies', phone: '9800002001', email: 'orders@everest.example.test', address: 'Teku, Kathmandu', purchase_count: 3, total_purchased: 42800 },
      { id: 2, name: 'Pooja Shrestha', company: 'Himalayan Office Goods', phone: '9800002002', email: 'sales@himalayan.example.test', address: 'Patan Industrial Area', purchase_count: 2, total_purchased: 18750 },
      { id: 3, name: 'Kiran Adhikari', company: 'Metro Lifestyle Wholesale', phone: '9800002003', email: 'hello@metro.example.test', address: 'New Road, Kathmandu', purchase_count: 1, total_purchased: 9200 }
    ],
    sales: [],
    movements: [],
    purchases: [],
    nextIds: { product: 15, category: 6, customer: 6, supplier: 4, sale: 9, movement: 20, purchase: 1 }
  };

  const sampleSaleLines = [
    [[2, 1], [4, 2]], [[1, 1], [5, 1]], [[7, 1]], [[10, 1], [8, 2]],
    [[3, 1], [13, 2]], [[2, 1], [11, 1]], [[9, 1], [4, 1]], [[14, 1], [8, 1]]
  ];
  const sampleCustomers = ['Aarav Sharma', 'Maya Gurung', 'Rohan Thapa', 'Sita Karki', 'Nabin Rai', 'Walk-in Customer', 'Walk-in Customer', 'Aarav Sharma'];
  const samplePayments = ['upi', 'card', 'cash', 'upi', 'cash', 'card', 'upi', 'cash'];

  function makeSale(id, age, lineSpecs, customer, payment) {
    const items = lineSpecs.map(([productId, qty]) => {
      const product = seed.products.find((item) => item.id === productId);
      return {
        product_id: product.id,
        name: product.name,
        product_name: product.name,
        sku: product.sku,
        qty,
        quantity: qty,
        price: product.price,
        unit_price: product.price,
        subtotal: product.price * qty,
        cost_price: product.cost_price
      };
    });
    const subtotal = items.reduce((sum, item) => sum + item.subtotal, 0);
    const discount = id % 3 === 0 ? 100 : 0;
    const taxAmount = Number(((subtotal - discount) * 0.05).toFixed(2));
    const grandTotal = Number((subtotal - discount + taxAmount).toFixed(2));
    return {
      id,
      invoice_no: `INV-DEMO-${String(1000 + id)}`,
      customer,
      customer_name: customer,
      date: daysAgo(age),
      sale_date: daysAgo(age),
      payment_method: payment,
      subtotal,
      discount,
      tax_rate: 5,
      tax_amount: taxAmount,
      total: grandTotal,
      grand_total: grandTotal,
      status: 'completed',
      items_qty: items.reduce((sum, item) => sum + item.qty, 0),
      items
    };
  }

  seed.sales = sampleSaleLines.map((lines, index) => makeSale(index + 1, 7 - index, lines, sampleCustomers[index], samplePayments[index]));
  seed.movements = seed.products.slice(0, 12).map((product, index) => ({
    id: index + 1,
    product_id: product.id,
    product_name: product.name,
    sku: product.sku,
    movement_type: 'INITIAL',
    quantity: product.stock,
    previous_stock: 0,
    new_stock: product.stock,
    reference_id: 'DEMO-SEED',
    notes: 'Fictional sample inventory',
    created_at: `${daysAgo(12 - index)} 09:00:00`
  })).reverse();
  seed.nextIds.movement = seed.movements.length + 1;

  function isValidStore(value) {
    return value && Array.isArray(value.products) && Array.isArray(value.categories)
      && Array.isArray(value.customers) && Array.isArray(value.suppliers)
      && Array.isArray(value.sales) && Array.isArray(value.movements);
  }

  let canPersist = true;
  let store = seed;
  try {
    const stored = window.localStorage.getItem(storageKey);
    if (stored) {
      const parsed = JSON.parse(stored);
      if (isValidStore(parsed)) store = parsed;
      else console.warn('SumYam Retail demo data was invalid; using the built-in fictional sample data.');
    }
  } catch (error) {
    canPersist = false;
    console.warn('SumYam Retail demo persistence is unavailable; changes will last for this page session.', error);
  }

  function save() {
    if (!canPersist) return;
    try {
      window.localStorage.setItem(storageKey, JSON.stringify(store));
    } catch (error) {
      canPersist = false;
      console.warn('Could not save SumYam Retail demo changes; continuing in memory for this session.', error);
    }
  }

  const ok = (data, message = 'Demo data loaded.') => ({ success: true, data, message });
  const fail = (message) => ({ success: false, data: null, message });
  const activeProducts = () => store.products.filter((item) => item.status !== 'inactive');
  const stockStatus = (product) => product.stock <= 0 ? 'out' : product.stock <= product.min ? 'low' : 'healthy';
  const productView = (product) => ({
    ...product,
    selling_price: product.price,
    stock_quantity: product.stock,
    min_stock_level: product.min,
    category_id: store.categories.find((category) => category.name === product.category)?.id ?? null,
    stock_status: stockStatus(product)
  });
  const categoryFor = (name) => {
    const normalized = String(name || 'General').trim() || 'General';
    let category = store.categories.find((item) => item.name.toLowerCase() === normalized.toLowerCase());
    if (!category) {
      category = { id: store.nextIds.category++, name: normalized };
      store.categories.push(category);
    }
    return category.name;
  };
  const addMovement = (product, movementType, quantity, previousStock, referenceId, notes) => {
    store.movements.unshift({
      id: store.nextIds.movement++,
      product_id: product.id,
      product_name: product.name,
      sku: product.sku,
      movement_type: movementType,
      quantity,
      previous_stock: previousStock,
      new_stock: product.stock,
      reference_id: referenceId,
      notes,
      created_at: nowText()
    });
  };
  const customerStats = (customer) => {
    const sales = store.sales.filter((sale) => sale.customer === customer.name);
    return {
      ...customer,
      purchases: sales.length || customer.purchases || 0,
      total_spent: Number(sales.reduce((sum, sale) => sum + sale.grand_total, 0).toFixed(2)) || customer.total_spent || 0
    };
  };

  function analytics() {
    const products = activeProducts();
    const sales = store.sales.filter((sale) => sale.status === 'completed');
    const low = products.filter((item) => item.stock > 0 && item.stock <= item.min);
    const out = products.filter((item) => item.stock <= 0);
    const today = daysAgo(0);
    const salesToday = sales.filter((sale) => sale.sale_date === today);
    const chart = Array.from({ length: 7 }, (_, index) => {
      const date = daysAgo(6 - index);
      const daySales = sales.filter((sale) => sale.sale_date === date);
      const label = new Date(`${date}T12:00:00`).toLocaleDateString('en-US', { weekday: 'short' });
      return {
        date,
        label,
        revenue: Number(daySales.reduce((sum, sale) => sum + sale.grand_total, 0).toFixed(2)),
        orders: daySales.length
      };
    });
    const sold = new Map();
    sales.forEach((sale) => sale.items.forEach((item) => {
      const entry = sold.get(item.product_id) || { name: item.name, sku: item.sku, qty_sold: 0, revenue: 0 };
      entry.qty_sold += item.qty;
      entry.revenue += item.subtotal;
      sold.set(item.product_id, entry);
    }));
    const categories = store.categories.map((category) => {
      const rows = products.filter((product) => product.category === category.name);
      return {
        category: category.name,
        product_count: rows.length,
        total_stock: rows.reduce((sum, product) => sum + product.stock, 0)
      };
    }).filter((category) => category.product_count);
    const totalRevenue = sales.reduce((sum, sale) => sum + sale.grand_total, 0);
    return {
      summary: {
        total_products: products.length,
        total_units: products.reduce((sum, product) => sum + product.stock, 0),
        total_categories: store.categories.length,
        total_customers: store.customers.length,
        total_revenue: Number(totalRevenue.toFixed(2)),
        total_orders: sales.length,
        average_bill: sales.length ? Number((totalRevenue / sales.length).toFixed(2)) : 0,
        today_revenue: Number(salesToday.reduce((sum, sale) => sum + sale.grand_total, 0).toFixed(2)),
        today_orders: salesToday.length,
        gross_profit: Number(sales.reduce((sum, sale) => sum + sale.items.reduce((lineSum, item) => lineSum + ((item.price - item.cost_price) * item.qty), 0), 0).toFixed(2)),
        inventory_retail_value: Number(products.reduce((sum, product) => sum + product.stock * product.price, 0).toFixed(2)),
        inventory_cost_value: Number(products.reduce((sum, product) => sum + product.stock * product.cost_price, 0).toFixed(2)),
        healthy_stock_count: products.filter((product) => product.stock > product.min).length,
        low_stock_count: low.length,
        out_of_stock_count: out.length
      },
      chart_7days: chart,
      top_products: [...sold.values()].sort((a, b) => b.qty_sold - a.qty_sold).slice(0, 6),
      category_breakdown: categories,
      recent_sales: [...sales].sort((a, b) => b.id - a.id).slice(0, 5).map((sale) => ({
        invoice_no: sale.invoice_no,
        customer: sale.customer,
        date: sale.date,
        total: sale.grand_total,
        status: sale.status,
        items_qty: sale.items_qty
      })),
      low_stock_alerts: [...low, ...out].map((product) => ({
        id: product.id,
        name: product.name,
        sku: product.sku,
        category: product.category,
        stock_quantity: product.stock,
        min_stock_level: product.min,
        status: product.stock <= 0 ? 'out' : 'low'
      }))
    };
  }

  const fakeCatalog = [
    { external_id: 501, name: 'Mechanical Gaming Keyboard RGB', category: 'Electronics', selling_price: 3499, cost_price: 2200, stock_quantity: 15, min_stock_level: 5, suggested_sku: 'EXT-MECH-501', is_external_demo: true, source: 'SumYam Retail fictional static catalog' },
    { external_id: 502, name: 'Ergonomic Vertical Mouse', category: 'Electronics', selling_price: 1299, cost_price: 750, stock_quantity: 22, min_stock_level: 5, suggested_sku: 'EXT-VMSE-502', is_external_demo: true, source: 'SumYam Retail fictional static catalog' },
    { external_id: 503, name: 'Aluminium Laptop Stand', category: 'Accessories', selling_price: 1199, cost_price: 600, stock_quantity: 18, min_stock_level: 5, suggested_sku: 'EXT-LSTD-503', is_external_demo: true, source: 'SumYam Retail fictional static catalog' },
    { external_id: 504, name: 'Stainless Steel Thermal Flask 1L', category: 'Lifestyle', selling_price: 849, cost_price: 450, stock_quantity: 30, min_stock_level: 5, suggested_sku: 'EXT-FLSK-504', is_external_demo: true, source: 'SumYam Retail fictional static catalog' },
    { external_id: 505, name: 'Smart LED Desk Bar Lamp', category: 'Home', selling_price: 2199, cost_price: 1350, stock_quantity: 12, min_stock_level: 5, suggested_sku: 'EXT-LAMP-502', is_external_demo: true, source: 'SumYam Retail fictional static catalog' },
    { external_id: 506, name: 'Braided 65W Fast Cable 2m', category: 'Accessories', selling_price: 399, cost_price: 180, stock_quantity: 45, min_stock_level: 5, suggested_sku: 'EXT-CBL6-506', is_external_demo: true, source: 'SumYam Retail fictional static catalog' }
  ];

  function listProducts(params = {}) {
    const search = String(params.search || '').trim().toLowerCase();
    const category = String(params.category || 'all');
    const stockFilter = params.stock_status || 'all';
    const sort = params.sort || 'default';
    let list = store.products.filter((product) => product.status !== 'inactive').filter((product) => {
      const text = `${product.name} ${product.sku} ${product.barcode || ''} ${product.category}`.toLowerCase();
      return (!search || text.includes(search))
        && (category === 'all' || category === '' || product.category === category || String(store.categories.find((item) => item.name === product.category)?.id) === category)
        && (stockFilter === 'all' || stockStatus(product) === stockFilter);
    }).map(productView);
    const comparators = {
      name: (a, b) => a.name.localeCompare(b.name),
      name_asc: (a, b) => a.name.localeCompare(b.name),
      price_low: (a, b) => a.price - b.price,
      priceLow: (a, b) => a.price - b.price,
      price_high: (a, b) => b.price - a.price,
      priceHigh: (a, b) => b.price - a.price,
      stock_low: (a, b) => a.stock - b.stock,
      stockLow: (a, b) => a.stock - b.stock,
      stock_high: (a, b) => b.stock - a.stock,
      stockHigh: (a, b) => b.stock - a.stock
    };
    list.sort(comparators[sort] || ((a, b) => b.id - a.id));
    return list;
  }

  function createProduct(input = {}) {
    const name = String(input.name || '').trim();
    const sku = String(input.sku || '').trim().toUpperCase();
    const price = Number(input.price ?? input.selling_price);
    const stock = Number(input.stock ?? input.stock_quantity ?? 0);
    const min = Number(input.min ?? input.min_stock_level ?? 5);
    if (!name || !sku) return fail('Product name and SKU are required.');
    if (store.products.some((product) => product.sku.toLowerCase() === sku.toLowerCase())) return fail(`SKU '${sku}' already exists. Please choose a unique SKU.`);
    if (!Number.isFinite(price) || price < 0 || !Number.isInteger(stock) || stock < 0 || !Number.isInteger(min) || min < 0) return fail('Enter valid non-negative prices and stock quantities.');
    const category = categoryFor(input.category);
    const product = {
      id: store.nextIds.product++, name, sku, barcode: String(input.barcode || ''),
      category, cost_price: Number(input.cost_price) || 0, price, stock, min,
      unit: String(input.unit || 'pcs'), status: 'active'
    };
    store.products.push(product);
    if (stock > 0) addMovement(product, 'INITIAL', stock, 0, 'DEMO_PRODUCT', 'Added in static demo');
    save();
    return ok(productView(product), 'Product saved in this browser demo.');
  }

  function createSale(input = {}) {
    if (!Array.isArray(input.items) || !input.items.length) return fail('Cannot process an empty sale.');
    const items = [];
    const reserved = new Map();
    for (const requested of input.items) {
      const product = store.products.find((item) => item.id === Number(requested.id));
      const qty = Number(requested.qty);
      if (!product || product.status === 'inactive') return fail('A selected product is no longer available.');
      if (!Number.isInteger(qty) || qty < 1) return fail('Sale quantities must be positive whole numbers.');
      const alreadyReserved = reserved.get(product.id) || 0;
      if (product.stock - alreadyReserved < qty) return fail(`Insufficient stock for ${product.name}. Available: ${product.stock - alreadyReserved}.`);
      reserved.set(product.id, alreadyReserved + qty);
      items.push({ product, qty });
    }
    const subtotal = items.reduce((sum, row) => sum + row.product.price * row.qty, 0);
    const discount = Math.min(subtotal, Math.max(0, Number(input.discount) || 0));
    const taxAmount = Number(((subtotal - discount) * 0.05).toFixed(2));
    const grandTotal = Number((subtotal - discount + taxAmount).toFixed(2));
    const customerId = input.customer_id ? Number(input.customer_id) : null;
    const customerRecord = customerId === null ? null : store.customers.find((item) => item.id === customerId);
    if (customerId !== null && !customerRecord) return fail('Selected demo customer was not found.');
    const customer = customerRecord?.name || 'Walk-in Customer';
    const saleId = store.nextIds.sale++;
    const sale = {
      id: saleId,
      invoice_no: `INV-DEMO-${String(1000 + saleId)}`,
      customer,
      customer_name: customer,
      customer_id: customerRecord?.id ?? null,
      date: daysAgo(0),
      sale_date: daysAgo(0),
      payment_method: ['cash', 'card', 'upi', 'credit'].includes(input.payment_method) ? input.payment_method : 'cash',
      subtotal,
      discount,
      tax_rate: 5,
      tax_amount: taxAmount,
      total: grandTotal,
      grand_total: grandTotal,
      status: 'completed',
      items_qty: items.reduce((sum, row) => sum + row.qty, 0),
      items: items.map(({ product, qty }) => ({
        product_id: product.id,
        name: product.name,
        product_name: product.name,
        sku: product.sku,
        qty,
        quantity: qty,
        price: product.price,
        unit_price: product.price,
        subtotal: product.price * qty,
        cost_price: product.cost_price
      }))
    };
    items.forEach(({ product, qty }) => {
      const previous = product.stock;
      product.stock -= qty;
      addMovement(product, 'SALE', -qty, previous, sale.invoice_no, `Demo sale ${sale.invoice_no}`);
    });
    store.sales.push(sale);
    if (customerRecord) {
      customerRecord.purchases = Number(customerRecord.purchases || 0) + 1;
      customerRecord.total_spent = Number(customerRecord.total_spent || 0) + grandTotal;
    }
    save();
    return ok(sale, 'Demo sale completed and saved in this browser.');
  }

  function handleGet(endpoint, params) {
    switch (endpoint) {
      case 'status.php':
        return ok({ status: 'demo', app_mode: 'static_demo', storage: canPersist ? 'browser local storage' : 'page memory' }, 'Running with fictional static demo data.');
      case 'products.php': {
        const products = listProducts(params);
        const single = params.id ? products.find((product) => product.id === Number(params.id)) : null;
        return params.id ? (single ? ok(single) : fail('Product not found.')) : ok(products);
      }
      case 'categories.php':
        return ok(store.categories.map((category) => ({
          ...category,
          product_count: store.products.filter((product) => product.category === category.name && product.status !== 'inactive').length,
          total_units: store.products.filter((product) => product.category === category.name && product.status !== 'inactive').reduce((sum, product) => sum + product.stock, 0)
        })).sort((a, b) => a.name.localeCompare(b.name)));
      case 'customers.php': {
        const query = String(params.search || '').toLowerCase();
        return ok(store.customers.map(customerStats).filter((customer) =>
          `${customer.name} ${customer.phone} ${customer.email} ${customer.address}`.toLowerCase().includes(query)
        ).sort((a, b) => a.name.localeCompare(b.name)));
      }
      case 'suppliers.php': {
        const query = String(params.search || '').toLowerCase();
        return ok(store.suppliers.filter((supplier) =>
          `${supplier.name} ${supplier.company} ${supplier.phone} ${supplier.email}`.toLowerCase().includes(query)
        ).map((supplier) => ({
          ...supplier,
          purchase_count: store.purchases.filter((purchase) => purchase.supplier_id === supplier.id).length || supplier.purchase_count || 0,
          total_purchased: store.purchases.filter((purchase) => purchase.supplier_id === supplier.id).reduce((sum, purchase) => sum + purchase.total_amount, 0) || supplier.total_purchased || 0
        })).sort((a, b) => a.company.localeCompare(b.company)));
      }
      case 'sales.php': {
        if (params.invoice_no || params.id) {
          const sale = store.sales.find((item) => item.invoice_no === String(params.invoice_no) || item.id === Number(params.id));
          return sale ? ok(sale) : fail('Invoice not found.');
        }
        const query = String(params.search || '').toLowerCase();
        const limit = Math.min(100, Math.max(1, Number(params.limit) || 50));
        const list = [...store.sales].sort((a, b) => b.id - a.id).filter((sale) =>
          `${sale.invoice_no} ${sale.customer}`.toLowerCase().includes(query)
          && (!params.date_from || sale.date >= params.date_from)
          && (!params.date_to || sale.date <= params.date_to)
        ).slice(0, limit);
        return ok(list);
      }
      case 'analytics.php':
        return ok(analytics());
      case 'stock.php':
        return ok(store.movements.filter((movement) => !params.product_id || movement.product_id === Number(params.product_id)).slice(0, Math.min(200, Number(params.limit) || 50)));
      case 'purchases.php':
        return ok([...store.purchases].reverse());
      case 'fake-api.php':
        return ok({
          source_type: 'curated_static_demo',
          source_origin: 'Built-in sample catalog',
          note: 'Fictional sample items; importing adds them only to this browser demo.',
          catalog: fakeCatalog.map((item) => ({ ...item }))
        });
      default:
        return fail(`No static demo data is available for ${endpoint}.`);
    }
  }

  function handlePost(endpoint, input) {
    switch (endpoint) {
      case 'categories.php': {
        const name = String(input.name || '').trim();
        if (!name) return fail('Category name is required.');
        if (store.categories.some((category) => category.name.toLowerCase() === name.toLowerCase())) return fail('A category with this name already exists.');
        const category = { id: store.nextIds.category++, name, description: String(input.description || '') };
        store.categories.push(category);
        save();
        return ok(category, 'Category added to this browser demo.');
      }
      case 'customers.php': {
        const name = String(input.name || '').trim();
        const phone = String(input.phone || '').trim();
        if (!name || !phone) return fail('Customer name and phone number are required.');
        const customer = {
          id: store.nextIds.customer++,
          name,
          phone,
          email: String(input.email || ''),
          address: String(input.address || ''),
          purchases: 0,
          total_spent: 0
        };
        store.customers.push(customer);
        save();
        return ok(customer, 'Customer added to this browser demo.');
      }
      case 'suppliers.php': {
        const name = String(input.name || '').trim();
        const company = String(input.company || '').trim();
        const phone = String(input.phone || '').trim();
        if (!name || !company || !phone) return fail('Supplier contact, company, and phone are required.');
        const supplier = {
          id: store.nextIds.supplier++,
          name,
          company,
          phone,
          email: String(input.email || ''),
          address: String(input.address || ''),
          purchase_count: 0,
          total_purchased: 0
        };
        store.suppliers.push(supplier);
        save();
        return ok(supplier, 'Supplier added to this browser demo.');
      }
      case 'products.php':
        return createProduct(input);
      case 'sales.php':
        return createSale(input);
      case 'stock.php': {
        const product = store.products.find((item) => item.id === Number(input.id ?? input.product_id));
        if (!product) return fail('Product not found.');
        const previous = product.stock;
        const next = input.delta !== undefined ? previous + Number(input.delta) : Number(input.new_stock ?? input.stock);
        if (!Number.isInteger(next) || next < 0) return fail('Stock must be a non-negative whole number.');
        const delta = next - previous;
        product.stock = next;
        addMovement(product, delta >= 0 ? 'ADJUSTMENT_ADD' : 'ADJUSTMENT_SUB', delta, previous, 'MANUAL_ADJUST', String(input.notes || 'Manual demo stock adjustment'));
        save();
        return ok({ product_id: product.id, name: product.name, previous_stock: previous, new_stock: next, change: delta }, `${product.name} demo stock updated to ${next}.`);
      }
      case 'purchases.php': {
        const supplier = store.suppliers.find((item) => item.id === Number(input.supplier_id));
        if (!Array.isArray(input.items) || !input.items.length) return fail('Please specify at least one product to restock.');
        const grouped = new Map();
        for (const row of input.items) {
          const product = store.products.find((item) => item.id === Number(row.product_id ?? row.id));
          const quantity = Number(row.quantity ?? row.qty);
          const unitCost = Number(row.unit_cost) || (product ? Number(product.cost_price) : 0);
          if (!product || !Number.isInteger(quantity) || quantity <= 0) return fail('Choose an existing product and a positive restock quantity.');
          const entry = grouped.get(product.id) || { product_id: product.id, product_name: product.name, quantity: 0, unit_cost: unitCost };
          if (entry.unit_cost !== unitCost) return fail('Duplicate product lines must use the same unit cost.');
          entry.quantity += quantity;
          if (entry.quantity > 100000) return fail('Requested purchase quantity exceeds the allowed limit.');
          grouped.set(product.id, entry);
        }
        const rows = [...grouped.values()];
        const id = store.nextIds.purchase++;
        const poNumber = `PO-DEMO-${String(id).padStart(4, '0')}`;
        const total = rows.reduce((sum, row) => sum + row.unit_cost * row.quantity, 0);
        const purchase = { id, po_number: poNumber, supplier_id: supplier?.id ?? null, supplier_name: supplier?.company || input.supplier_name || 'General Supplier', total_amount: total, status: 'ordered', purchase_date: daysAgo(0), notes: input.notes || '', items_count: rows.length, items: rows };
        store.purchases.push(purchase);
        save();
        return ok(purchase, `Demo purchase order ${poNumber} created. Inventory changes after receipt is confirmed.`);
      }
      case 'fake-api.php': {
        const result = createProduct({
          name: input.name,
          sku: input.suggested_sku || input.sku,
          category: input.category,
          cost_price: input.cost_price,
          price: input.selling_price ?? input.price,
          stock: input.stock_quantity ?? input.stock,
          min: input.min_stock_level ?? input.min,
          unit: 'pcs'
        });
        if (result.success) return ok({ ...result.data, sku: result.data.sku }, `'${result.data.name}' added to the browser demo.`);
        if (!String(result.message).includes('already exists')) return result;
        return createProduct({ ...input, sku: `${input.suggested_sku || input.sku}-${store.nextIds.product}`, price: input.selling_price ?? input.price, stock: input.stock_quantity ?? input.stock, min: input.min_stock_level ?? input.min });
      }
      default:
        return fail(`This action is not available in static demo mode (${endpoint}).`);
    }
  }

  function handlePut(endpoint, input) {
    if (endpoint === 'purchases.php') {
      const purchase = store.purchases.find((item) => item.id === Number(input.id));
      if (!purchase) return fail('Purchase order not found.');
      if (purchase.status === 'received') return ok({ id: purchase.id, status: 'received', already_received: true }, 'Purchase order was already received.');
      if (purchase.status !== 'ordered' || input.action !== 'receive') return fail('Only an ordered purchase can be received.');
      const rows = purchase.items || [];
      if (!rows.length || rows.some((row) => !store.products.some((product) => product.id === Number(row.product_id)))) return fail('A product in this purchase order is unavailable.');
      rows.forEach((row) => {
        const product = store.products.find((item) => item.id === Number(row.product_id));
        const previous = product.stock;
        product.stock += Number(row.quantity);
        addMovement(product, 'PURCHASE', Number(row.quantity), previous, purchase.po_number, 'Received in browser demo');
      });
      purchase.status = 'received';
      purchase.received_at = nowText();
      save();
      return ok({ id: purchase.id, status: 'received' }, 'Demo purchase order received and inventory updated.');
    }
    if (endpoint === 'products.php') {
      const product = store.products.find((item) => item.id === Number(input.id));
      if (!product) return fail('Product not found.');
      const sku = String(input.sku || product.sku).trim().toUpperCase();
      if (store.products.some((item) => item.id !== product.id && item.sku.toLowerCase() === sku.toLowerCase())) return fail(`SKU '${sku}' already exists.`);
      const price = Number(input.price ?? input.selling_price ?? product.price);
      const stock = Number(input.stock ?? input.stock_quantity ?? product.stock);
      const min = Number(input.min ?? input.min_stock_level ?? product.min);
      if (!String(input.name || product.name).trim() || !sku || !Number.isFinite(price) || price < 0 || !Number.isInteger(stock) || stock < 0 || !Number.isInteger(min) || min < 0) return fail('Enter valid product details and non-negative stock.');
      product.name = String(input.name || product.name).trim();
      product.sku = sku;
      product.barcode = String(input.barcode ?? product.barcode);
      product.category = categoryFor(input.category ?? product.category);
      product.unit = String(input.unit || product.unit);
      product.cost_price = Number(input.cost_price ?? product.cost_price);
      product.price = price;
      product.stock = stock;
      product.min = min;
      save();
      return ok(productView(product), 'Product updated in this browser demo.');
    }
    if (endpoint === 'customers.php' || endpoint === 'suppliers.php') {
      const list = endpoint === 'customers.php' ? store.customers : store.suppliers;
      const record = list.find((item) => item.id === Number(input.id));
      if (!record) return fail('Record not found.');
      const updated = { ...record, ...input, id: record.id };
      if (!updated.name || !updated.phone || (endpoint === 'suppliers.php' && !updated.company)) return fail('Name and phone are required.');
      Object.assign(record, updated);
      save();
      return ok(record, 'Record updated in this browser demo.');
    }
    return fail(`This action is not available in static demo mode (${endpoint}).`);
  }

  function handleDelete(endpoint, params) {
    const id = Number(params.id);
    if (endpoint === 'products.php') {
      const product = store.products.find((item) => item.id === id);
      if (!product) return fail('Product not found.');
      if (store.sales.some((sale) => sale.items.some((item) => item.product_id === id))) {
        product.status = 'inactive';
        returnAfterSave();
        return ok({ id }, 'Product archived to preserve demo invoice history.');
      }
      store.products = store.products.filter((item) => item.id !== id);
      returnAfterSave();
      return ok({ id }, 'Product removed from this browser demo.');
    }
    if (endpoint === 'customers.php') {
      store.customers = store.customers.filter((item) => item.id !== id);
      returnAfterSave();
      return ok({ id }, 'Customer removed from this browser demo.');
    }
    if (endpoint === 'suppliers.php') {
      store.suppliers = store.suppliers.filter((item) => item.id !== id);
      returnAfterSave();
      return ok({ id }, 'Supplier removed from this browser demo.');
    }
    return fail(`This action is not available in static demo mode (${endpoint}).`);
  }

  function returnAfterSave() {
    save();
  }

  window.SmartStoreDemo = {
    handle(endpoint, method, params = {}, input = {}) {
      const file = String(endpoint).split('/').pop();
      switch (method) {
        case 'GET': return handleGet(file, params);
        case 'POST': return handlePost(file, input);
        case 'PUT': return handlePut(file, input);
        case 'DELETE': return handleDelete(file, params);
        default: return fail(`Unsupported demo request: ${method}.`);
      }
    }
  };
})();
