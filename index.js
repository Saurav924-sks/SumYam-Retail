/**
 * SumYam Retail - Retail management and inventory operations platform
 * Maintainer: Saurav Shah Teli
 *
 * Uses the PHP/MySQL API in live mode and browser-local data in explicit static demo mode.
 */

// Global Application State
const state = {
  products: [],
  categories: [],
  customers: [],
  suppliers: [],
  sales: [],
  purchases: [],
  analytics: null,
  cart: [],
  connection: {
    connected: false,
    database: '',
    version: '',
    error: ''
  },
  activeSection: 'dashboard',
  editingProductId: null,
  editingCustomerId: null,
  editingSupplierId: null
};
const DEMO_MODE = Boolean(window.SMARTSTORE_DEMO_MODE);
let csrfToken = '';
let currentUser = null;
const pendingIdempotency = new Map();

// DOM Selectors Helper
const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => [...document.querySelectorAll(selector)];

// Formatting Utilities
const money = (num) => '₹' + Number(num || 0).toLocaleString('en-IN', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
});

const esc = (str) => String(str ?? '').replace(/[&<>"']/g, (m) => ({
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#039;'
}[m]));

// Toast Notification System
function showToast(message, type = 'success') {
  const container = $('#toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.textContent = message;

  container.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));

  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 3200);
}

// Universal Modal System
function openModal(htmlContent) {
  const overlay = $('#modalOverlay');
  const box = $('#modalBox');
  if (!overlay || !box) return;

  box.innerHTML = htmlContent;
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal() {
  const overlay = $('#modalOverlay');
  if (!overlay) return;

  overlay.classList.remove('open');
  document.body.style.overflow = '';
}

$('#modalOverlay')?.addEventListener('click', (e) => {
  if (e.target.id === 'modalOverlay') closeModal();
});

// Confirmation Dialog Utility
function showConfirm(title, message, onConfirm) {
  openModal(`
    <div class="modal-header">
      <div>
        <h3>${esc(title)}</h3>
        <p>${esc(message)}</p>
      </div>
      <button class="modal-close-btn" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button type="button" class="btn btn-danger" id="confirmActionBtn">Confirm</button>
    </div>
  `);

  $('#confirmActionBtn')?.addEventListener('click', () => {
    closeModal();
    if (typeof onConfirm === 'function') onConfirm();
  });
}

// =========================================================================
// API CLIENT - Async Fetch from PHP Backend
// =========================================================================
const API = {
  baseUrl: 'api',
  async request(method, endpoint, params = {}, body = {}, options = {}) {
    if (DEMO_MODE) return window.SmartStoreDemo.handle(endpoint.split('?')[0], method, params, body);
    const url = new URL(`${this.baseUrl}/${endpoint}`, window.location.href);
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') url.searchParams.append(k, v);
    });
    const headers = { Accept: 'application/json' };
    const init = { method, credentials: 'same-origin', headers };
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      if (!options.skipCsrf) headers['X-CSRF-Token'] = csrfToken;
      if (method !== 'DELETE') {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      }
    }
    try {
      const response = await fetch(url.toString(), init);
      const result = await response.json();
      if (response.status === 401 && !options.skipAuthExpiry && !endpoint.startsWith('auth.php')) {
        showLoginGate('Your session expired. Sign in again to continue.');
      }
      return result;
    } catch (err) {
      console.error(`${method} ${endpoint} failed:`, err);
      return { success: false, message: 'Server communication failed. Retry the same operation to avoid duplicates.' };
    }
  },
  get(endpoint, params = {}, options = {}) { return this.request('GET', endpoint, params, {}, options); },
  post(endpoint, body = {}, options = {}) { return this.request('POST', endpoint, {}, body, options); },
  put(endpoint, body = {}, options = {}) { return this.request('PUT', endpoint, {}, body, options); },
  delete(endpoint, params = {}, options = {}) { return this.request('DELETE', endpoint, params, {}, options); }
};

function makeIdempotencyKey(operation, payload) {
  const signature = JSON.stringify(payload);
  let pending = pendingIdempotency.get(operation);
  if (!pending) {
    try { pending = JSON.parse(sessionStorage.getItem(`smartstore_pending_${operation}`) || 'null'); } catch (_) { pending = null; }
  }
  if (pending?.signature === signature) return pending.key;
  const key = crypto.randomUUID();
  pending = { signature, key };
  pendingIdempotency.set(operation, pending);
  try { sessionStorage.setItem(`smartstore_pending_${operation}`, JSON.stringify(pending)); } catch (_) { /* Keep the key in memory for this page session. */ }
  return key;
}

function clearIdempotencyKey(operation) {
  pendingIdempotency.delete(operation);
  try { sessionStorage.removeItem(`smartstore_pending_${operation}`); } catch (_) { /* No persisted key to clear. */ }
}

function showLoginGate(message = '') {
  csrfToken = '';
  currentUser = null;
  state.products = [];
  state.categories = [];
  state.customers = [];
  state.suppliers = [];
  state.purchases = [];
  state.sales = [];
  state.analytics = null;
  state.cart = [];
  if ($('#posDiscountInput')) $('#posDiscountInput').value = 0;
  renderCart();
  document.body.classList.add('auth-locked');
  const gate = $('#authGate');
  if (gate) gate.hidden = false;
  const error = $('#loginError');
  if (error) { error.textContent = message; error.hidden = !message; }
  $('#userName').textContent = 'Signed out';
  $('#userRole').textContent = '';
  $('#logoutBtn').hidden = true;
  $('#importFakeApiBtn').hidden = true;
  applyRolePermissions();
}

function canManageRecords() {
  return DEMO_MODE || currentUser?.role === 'admin';
}

function applyRolePermissions() {
  const canAdmin = canManageRecords();
  $$('[data-admin-only]').forEach((element) => { element.hidden = !canAdmin; });
  $('#importFakeApiBtn').hidden = !DEMO_MODE;
}

function showAuthenticatedUser(user) {
  currentUser = user;
  document.body.classList.remove('auth-locked');
  $('#authGate').hidden = true;
  $('#userName').textContent = user.display_name || user.email;
  $('#userRole').textContent = user.role === 'admin' ? 'Administrator' : 'Staff';
  $('#userAvatar').textContent = String(user.display_name || user.email).trim().slice(0, 2).toUpperCase();
  $('#logoutBtn').hidden = false;
  applyRolePermissions();
}

async function initializeSession() {
  if (DEMO_MODE) return true;
  const response = await API.get('auth.php?action=session', {}, { skipAuthExpiry: true });
  if (response.success && response.data?.authenticated) {
    csrfToken = response.data.csrf_token || '';
    if (!csrfToken) {
      showLoginGate('The server session is missing its request token. Sign in again.');
      return false;
    }
    showAuthenticatedUser(response.data.user);
    return Boolean(csrfToken);
  }
  showLoginGate();
  return false;
}

$('#loginForm')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = $('#loginBtn');
  const error = $('#loginError');
  const loginForm = event.currentTarget;
  button.disabled = true;
  error.hidden = true;
  const form = new FormData(loginForm);
  const result = await API.post('auth.php?action=login', {
    email: form.get('email'), password: form.get('password')
  }, { skipCsrf: true, skipAuthExpiry: true });
  button.disabled = false;
  if (!result.success || !result.data?.authenticated) {
    error.textContent = result.message || 'Sign in failed.';
    error.hidden = false;
    return;
  }
  csrfToken = result.data.csrf_token || '';
  showAuthenticatedUser(result.data.user);
  loginForm.reset();
  await loadAllData();
});

$('#logoutBtn')?.addEventListener('click', async () => {
  const result = await API.post('auth.php?action=logout', {}, { skipAuthExpiry: true });
  if (result.success) showLoginGate('You have signed out.');
  else showToast(result.message || 'Sign out failed.', 'error');
});

// =========================================================================
// NAVIGATION & ROUTING
// =========================================================================
function navigate(section) {
  state.activeSection = section;

  // Toggle sections
  $$('.section').forEach((sec) => {
    sec.classList.toggle('active', sec.id === `${section}Section`);
  });

  // Toggle active nav buttons
  $$('.nav-item').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.section === section);
  });

  // Update Breadcrumb & Heading
  const titles = {
    dashboard: 'Dashboard Overview',
    products: 'Product Catalog',
    inventory: 'Stock Inventory Control',
    sales: 'Sales & Billing Checkout',
    customers: 'Customers CRM',
    suppliers: 'Suppliers & Purchases',
    reports: 'Business Reports & Analytics',
    audit: 'Stock Movement Audit Log'
  };

  const breadcrumbs = {
    dashboard: 'SUMYAM RETAIL / OVERVIEW',
    products: 'SUMYAM RETAIL / PRODUCTS CATALOG',
    inventory: 'SUMYAM RETAIL / INVENTORY CONTROL',
    sales: 'SUMYAM RETAIL / POINT OF SALE',
    customers: 'SUMYAM RETAIL / CUSTOMER CRM',
    suppliers: 'SUMYAM RETAIL / SUPPLY CHAIN',
    reports: 'SUMYAM RETAIL / REPORTS & ANALYTICS',
    audit: 'SUMYAM RETAIL / AUDIT JOURNAL'
  };

  $('#pageHeading').textContent = titles[section] || 'Dashboard';
  $('#pageBreadcrumb').textContent = breadcrumbs[section] || 'SUMYAM RETAIL';

  // Close mobile sidebar if open
  $('#sidebar')?.classList.remove('open');
  window.scrollTo({ top: 0, behavior: 'smooth' });

  // Specific section refresh
  if (section === 'audit') loadAuditLog();
  if (section === 'reports') loadAnalytics();
}

// Bind navigation clicks
$$('.nav-item').forEach((btn) => {
  btn.addEventListener('click', () => navigate(btn.dataset.section));
});

document.addEventListener('click', (e) => {
  const target = e.target.closest('[data-navigate]');
  if (target) {
    navigate(target.dataset.navigate);
  }
});

// Mobile menu toggle
$('#menuToggleBtn')?.addEventListener('click', () => {
  $('#sidebar')?.classList.toggle('open');
});

// Theme switcher (Persisted in localStorage)
$('#themeToggleBtn')?.addEventListener('click', () => {
  document.body.classList.toggle('dark');
  const isDark = document.body.classList.contains('dark');
  localStorage.setItem('smartstore_theme', isDark ? 'dark' : 'light');
  updateThemeIcons(isDark);
});

function updateThemeIcons(isDark) {
  const sun = $('#themeIconSun');
  const moon = $('#themeIconMoon');
  if (sun && moon) {
    sun.style.display = isDark ? 'block' : 'none';
    moon.style.display = isDark ? 'none' : 'block';
  }
}

// Load initial theme preference
if (localStorage.getItem('smartstore_theme') === 'dark') {
  document.body.classList.add('dark');
  updateThemeIcons(true);
}

// =========================================================================
// DATA FETCHING & SYNCHRONIZATION
// =========================================================================
async function checkSystemStatus() {
  const res = await API.get('status.php');

  const dot = $('#dbPulseDot');
  const text = $('#dbStatusText');
  const sub = $('#dbStatusSub');
  const liveBadge = $('#liveBadge');
  const liveBadgeText = $('#liveBadgeText');

  if (DEMO_MODE) {
    state.connection = {
      connected: false,
      database: '',
      version: '',
      error: 'Static demo uses fictional sample data.'
    };
    if (dot) dot.className = 'pulse-dot';
    if (text) text.textContent = 'Static Demo';
    if (sub) sub.textContent = res.data?.storage === 'page memory'
      ? 'Sample data · changes last this session'
      : 'Sample data · saved in this browser';
    if (liveBadge) liveBadge.className = 'badge info';
    if (liveBadge) liveBadge.title = 'Static demo data; no backend connection';
    if (liveBadgeText) liveBadgeText.textContent = 'Sample data';
    const salesBadge = $('#salesDataBadge');
    if (salesBadge) {
      salesBadge.className = 'badge info';
      salesBadge.textContent = 'Static demo';
    }
    return;
  }

  if (res.success && res.data?.status === 'healthy') {
    if (liveBadge) liveBadge.title = 'Backend connection status';
    state.connection = {
      connected: true,
      database: res.data.database || '',
      version: res.data.server_version || '',
      error: ''
    };
    if (dot) dot.className = 'pulse-dot';
    if (text) text.textContent = 'MySQL Connected';
    if (sub) sub.textContent = res.data.server_version && res.data.database
      ? `${res.data.server_version} · ${res.data.database}`
      : 'Database connection is healthy';
    if (liveBadge) liveBadge.className = 'badge healthy';
    if (liveBadgeText) liveBadgeText.textContent = 'MySQL Live';
  } else {
    state.connection = {
      connected: false,
      database: '',
      version: '',
      error: res.message || 'Database unreachable'
    };
    if (dot) dot.className = 'pulse-dot error';
    if (text) text.textContent = 'MySQL Disconnected';
    if (sub) sub.textContent = 'Check XAMPP services';
    if (liveBadge) liveBadge.className = 'badge danger';
    if (liveBadgeText) liveBadgeText.textContent = 'Offline / Error';

    showToast('Database connection failed. Ensure MySQL is running in XAMPP.', 'error');
  }
}

async function loadAllData() {
  await checkSystemStatus();
  await Promise.all([
    loadProducts(),
    loadCategories(),
    loadCustomers(),
    loadSuppliers(),
    loadPurchases(),
    loadSales(),
    loadAnalytics()
  ]);
}

async function loadProducts() {
  const search = $('#productSearchInput')?.value || '';
  const category = $('#productCategoryFilter')?.value || 'all';
  const stock = $('#productStockFilter')?.value || 'all';
  const sort = $('#productSortFilter')?.value || 'default';

  const res = await API.get('products.php', {
    search,
    category,
    stock_status: stock,
    sort
  });

  if (res.success && Array.isArray(res.data)) {
    state.products = res.data;
    renderProducts();
    renderInventory();
    renderProductPicker();
    $('#navProductCount').textContent = state.products.length;
  }
}

async function loadCategories() {
  const res = await API.get('categories.php');
  if (res.success && Array.isArray(res.data)) {
    state.categories = res.data;
    populateCategoryFilters();
  }
}

async function loadCustomers() {
  const search = $('#customerSearchInput')?.value || '';
  const res = await API.get('customers.php', { search });
  if (res.success && Array.isArray(res.data)) {
    state.customers = res.data;
    renderCustomers();
    populateCustomerDropdown();
  }
}

async function loadSuppliers() {
  const search = $('#supplierSearchInput')?.value || '';
  const res = await API.get('suppliers.php', { search });
  if (res.success && Array.isArray(res.data)) {
    state.suppliers = res.data;
    renderSuppliers();
  }
}

async function loadPurchases() {
  const res = await API.get('purchases.php');
  if (res.success && Array.isArray(res.data)) {
    state.purchases = res.data;
    renderPurchaseOrders();
  }
}

function renderPurchaseOrders() {
  const tbody = $('#purchaseOrdersTableBody');
  if (!tbody) return;
  if (!state.purchases.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-state">No purchase orders recorded.</td></tr>';
    return;
  }
  tbody.innerHTML = state.purchases.map((purchase) => {
    const received = purchase.status === 'received';
    const receiptAction = received || !canManageRecords()
      ? `<span class="badge ${received ? 'healthy' : 'low'}">${esc(purchase.status)}</span>`
      : `<button class="btn btn-sm btn-primary" onclick="receivePurchaseOrder(${Number(purchase.id)})">Confirm receipt</button>`;
    return `<tr>
      <td><b>${esc(purchase.po_number || `PO #${purchase.id}`)}</b></td>
      <td>${esc(purchase.supplier_name || 'Supplier unavailable')}</td>
      <td>${esc(purchase.purchase_date || '')}</td>
      <td><span class="badge ${received ? 'healthy' : 'low'}">${esc(purchase.status)}</span></td>
      <td>${money(purchase.total_amount)}</td><td>${receiptAction}</td>
    </tr>`;
  }).join('');
}

async function receivePurchaseOrder(purchaseId) {
  const purchase = state.purchases.find((item) => Number(item.id) === Number(purchaseId));
  if (!purchase || purchase.status !== 'ordered') return;
  showConfirm('Confirm goods received', `Confirm physical receipt of ${purchase.po_number || `PO #${purchase.id}`}? Inventory will increase only after this confirmation.`, async () => {
    const result = await API.put('purchases.php', { id: Number(purchase.id), action: 'receive' });
    if (!result.success) {
      showToast(result.message || 'Purchase receipt failed. Inventory was not confirmed.', 'error');
      return;
    }
    showToast(result.message || 'Purchase order received. Inventory updated.');
    await Promise.all([loadPurchases(), loadProducts(), loadAnalytics(), loadSuppliers()]);
  });
}

async function loadSales() {
  const res = await API.get('sales.php', { limit: 50 });
  if (res.success && Array.isArray(res.data)) {
    state.sales = res.data;
    renderSalesHistory();
  }
}

async function loadAnalytics() {
  const res = await API.get('analytics.php');
  if (res.success && res.data) {
    state.analytics = res.data;
    renderDashboard();
    renderReports();
  }
}

async function loadAuditLog() {
  const tbody = $('#auditTableBody');
  if (!tbody) return;

  tbody.innerHTML = `<tr><td colspan="7" class="empty-state">Loading stock audit movements...</td></tr>`;
  const res = await API.get('stock.php', { limit: 100 });

  if (res.success && Array.isArray(res.data)) {
    if (!res.data.length) {
      tbody.innerHTML = `<tr><td colspan="7" class="empty-state">No stock movements recorded yet.</td></tr>`;
      return;
    }

    tbody.innerHTML = res.data.map((m) => {
      const typeClasses = {
        SALE: 'badge danger',
        PURCHASE: 'badge healthy',
        ADJUSTMENT_ADD: 'badge healthy',
        ADJUSTMENT_SUB: 'badge low',
        INITIAL: 'badge info'
      };
      const badgeCls = typeClasses[m.movement_type] || 'badge info';
      const deltaSign = m.quantity > 0 ? `+${m.quantity}` : `${m.quantity}`;

      return `
        <tr>
          <td class="cell-sub">${esc(m.created_at)}</td>
          <td><b>${esc(m.product_name)}</b><br><span class="cell-sub">${esc(m.sku)}</span></td>
          <td><span class="${badgeCls}">${esc(m.movement_type)}</span></td>
          <td><b style="color:${m.quantity >= 0 ? 'var(--green)' : 'var(--red)'}">${deltaSign}</b></td>
          <td>${m.previous_stock}</td>
          <td><b>${m.new_stock}</b></td>
          <td><span class="cell-sub">${esc(m.reference_id || '—')}</span><br>${esc(m.notes || '')}</td>
        </tr>
      `;
    }).join('');
  }
}

// =========================================================================
// RENDERING MODULES
// =========================================================================

// 1. Dashboard
function renderDashboard() {
  if (!state.analytics) return;
  const summary = state.analytics.summary || {};

  $('#kpiProducts').textContent = summary.total_products || 0;
  $('#kpiCategories').textContent = `${summary.total_categories || 0} categories`;
  $('#kpiTotalUnits').textContent = `${summary.total_units || 0} units`;
  $('#kpiRevenue').textContent = money(summary.total_revenue);
  $('#kpiValuation').textContent = money(summary.inventory_retail_value);
  $('#kpiCostValuation').textContent = 'Cost basis unverified';
  $('#kpiLowStock').textContent = summary.low_stock_count || 0;
  $('#kpiOutStock').textContent = `${summary.out_of_stock_count || 0} out of stock`;

  // Low Stock Banner
  const alertCount = (summary.low_stock_count || 0) + (summary.out_of_stock_count || 0);
  const banner = $('#lowStockBanner');
  const bannerText = $('#lowStockBannerText');
  const navBadge = $('#navLowStockBadge');

  if (alertCount > 0) {
    if (banner) banner.style.display = 'flex';
    if (bannerText) bannerText.textContent = `${alertCount} product${alertCount > 1 ? 's' : ''} require immediate inventory reorder or restocking.`;
    if (navBadge) {
      navBadge.style.display = 'inline-block';
      navBadge.textContent = alertCount;
    }
  } else {
    if (banner) banner.style.display = 'none';
    if (navBadge) navBadge.style.display = 'none';
  }

  // 7-Day Sales Bar Chart
  const chartEl = $('#dashboardSalesChart');
  const chartDays = state.analytics.chart_7days || [];
  const maxRevenue = Math.max(...chartDays.map((d) => d.revenue), 1);

  if (chartEl) {
    chartEl.innerHTML = chartDays.map((d) => {
      const heightPct = Math.max(8, Math.round((d.revenue / maxRevenue) * 90));
      return `
        <div class="bar-column">
          <div class="bar-fill" style="height:${heightPct}%" title="${d.label} (${d.date}): ${money(d.revenue)} (${d.orders} orders)"></div>
          <span class="bar-label">${d.label}</span>
        </div>
      `;
    }).join('');
  }

  // Stock Health Donut
  const healthy = summary.healthy_stock_count || 0;
  const low = summary.low_stock_count || 0;
  const out = summary.out_of_stock_count || 0;
  const total = (healthy + low + out) || 1;

  const healthyPct = Math.round((healthy / total) * 100);
  const lowPct = Math.round((low / total) * 100);

  $('#healthyPctText').textContent = `${healthyPct}%`;
  $('#healthyCountVal').textContent = healthy;
  $('#lowCountVal').textContent = low;
  $('#outCountVal').textContent = out;

  const donut = $('#stockDonutVisual');
  if (donut) {
    donut.style.background = `conic-gradient(
      var(--green) 0% ${healthyPct}%,
      var(--orange) ${healthyPct}% ${healthyPct + lowPct}%,
      var(--red) ${healthyPct + lowPct}% 100%
    )`;
  }

  // Recent Sales Table
  const recentTbody = $('#dashboardRecentSalesTbody');
  const recent = state.analytics.recent_sales || [];

  if (recentTbody) {
    if (!recent.length) {
      recentTbody.innerHTML = `<tr><td colspan="7" class="empty-state">No sales recorded yet.</td></tr>`;
      return;
    }

    recentTbody.innerHTML = recent.map((s) => `
      <tr>
        <td><b>${esc(s.invoice_no)}</b></td>
        <td>${esc(s.customer)}</td>
        <td class="cell-sub">${esc(s.date)}</td>
        <td>${s.items_qty} item${s.items_qty == 1 ? '' : 's'}</td>
        <td><b>${money(s.total)}</b></td>
        <td><span class="badge healthy">Completed</span></td>
        <td>
          <button class="btn btn-sm btn-outline" onclick="showInvoiceModal('${esc(s.invoice_no)}')">Invoice</button>
        </td>
      </tr>
    `).join('');
  }
}

// 2. Product Catalog
function populateCategoryFilters() {
  const filter = $('#productCategoryFilter');
  if (!filter) return;

  const curr = filter.value;
  const cats = state.categories.map((c) => c.name);

  filter.innerHTML = `<option value="all">All Categories</option>` +
    cats.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join('');

  if (cats.includes(curr)) filter.value = curr;
}

function renderProducts() {
  const tbody = $('#productsTableBody');
  if (!tbody) return;

  if (!state.products.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="empty-state">No products found matching your filter criteria.</td></tr>`;
    return;
  }

  const actions = canManageRecords();
  tbody.innerHTML = state.products.map((p) => {
    let badgeClass = 'healthy';
    let badgeText = 'Healthy';

    if (p.stock <= 0) {
      badgeClass = 'out';
      badgeText = 'Out of Stock';
    } else if (p.stock <= p.min) {
      badgeClass = 'low';
      badgeText = 'Low Stock';
    }

    return `
      <tr>
        <td>
          <div class="cell-primary">${esc(p.name)}</div>
          <div class="cell-sub">Unit: ${esc(p.unit || 'pcs')}</div>
        </td>
        <td>
          <b>${esc(p.sku)}</b>
          ${p.barcode ? `<div class="cell-sub">${esc(p.barcode)}</div>` : ''}
        </td>
        <td><span class="badge purple">${esc(p.category || 'Uncategorized')}</span></td>
        <td class="cell-sub">${money(p.cost_price)}</td>
        <td><b>${money(p.price)}</b></td>
        <td>
          <b>${p.stock}</b> <span class="cell-sub">/ min ${p.min}</span>
        </td>
        <td><span class="badge ${badgeClass}">${badgeText}</span></td>
        <td>
          ${actions ? `<div class="table-actions">
            <button class="btn btn-sm btn-secondary" onclick="openProductModal(${p.id})">Edit</button>
            <button class="btn btn-sm btn-outline" onclick="openQuickAdjustModal(${p.id})" title="Adjust Stock">±</button>
            <button class="btn btn-sm btn-outline" style="color:var(--red);" onclick="deleteProduct(${p.id})">Delete</button>
          </div>` : '<span class="cell-sub">Read only</span>'}
        </td>
      </tr>
    `;
  }).join('');
}

// 3. Inventory Stock Control
function renderInventory() {
  const tbody = $('#inventoryTableBody');
  if (!tbody) return;

  const healthy = state.products.filter((p) => p.stock > p.min).length;
  const low = state.products.filter((p) => p.stock > 0 && p.stock <= p.min).length;
  const out = state.products.filter((p) => p.stock <= 0).length;

  $('#invHealthyCount').textContent = healthy;
  $('#invLowCount').textContent = low;
  $('#invOutCount').textContent = out;

  const actions = canManageRecords();
  tbody.innerHTML = state.products.map((p) => {
    let statusClass = 'healthy';
    let statusLabel = 'Healthy';
    if (p.stock <= 0) {
      statusClass = 'out';
      statusLabel = 'Out of Stock';
    } else if (p.stock <= p.min) {
      statusClass = 'low';
      statusLabel = 'Low Stock';
    }

    return `
      <tr>
        <td>
          <b>${esc(p.name)}</b>
          <div class="cell-sub">SKU: ${esc(p.sku)} · Category: ${esc(p.category)}</div>
        </td>
        <td><strong style="font-size:15px;">${p.stock}</strong></td>
        <td>${p.min}</td>
        <td><span class="badge ${statusClass}">${statusLabel}</span></td>
        <td>
          ${actions ? `<div class="table-actions">
            <button class="btn btn-sm btn-secondary" onclick="quickStockDelta(${p.id}, -5)">−5</button>
            <button class="btn btn-sm btn-secondary" onclick="quickStockDelta(${p.id}, -1)">−1</button>
            <button class="btn btn-sm btn-secondary" onclick="quickStockDelta(${p.id}, 1)">＋1</button>
            <button class="btn btn-sm btn-secondary" onclick="quickStockDelta(${p.id}, 5)">＋5</button>
            <button class="btn btn-sm btn-outline" onclick="openQuickAdjustModal(${p.id})">Custom</button>
          </div>` : '<span class="cell-sub">Read only</span>'}
        </td>
      </tr>
    `;
  }).join('');
}

async function quickStockDelta(productId, delta) {
  const res = await API.post('stock.php', { id: productId, delta, notes: `Quick button adjust (${delta > 0 ? '+' : ''}${delta})` });
  if (res.success) {
    showToast(res.message);
    await Promise.all([loadProducts(), loadAnalytics()]);
  } else {
    showToast(res.message, 'error');
  }
}

// 4. POS Billing & Cart
function populateCustomerDropdown() {
  const sel = $('#posCustomerSelect');
  if (!sel) return;

  const current = sel.value;
  sel.innerHTML = `<option value="">Walk-in Customer (General)</option>` +
    state.customers.map((c) => `<option value="${Number(c.id)}">${esc(c.name)} (${esc(c.phone)})</option>`).join('');

  if (current) sel.value = current;
}

function renderProductPicker() {
  const grid = $('#posProductPickerGrid');
  if (!grid) return;

  const q = ($('#posProductSearch')?.value || '').toLowerCase().trim();
  const available = state.products.filter((p) => p.status !== 'inactive' && p.stock > 0 && (!q || `${p.name} ${p.sku} ${p.category}`.toLowerCase().includes(q)));

  if (!available.length) {
    grid.innerHTML = `<div class="empty-state" style="grid-column: 1/-1;">No available in-stock products found.</div>`;
    return;
  }

  grid.innerHTML = available.map((p) => `
    <div class="pick-product-card" onclick="addToCart(${p.id})">
      <h4>${esc(p.name)}</h4>
      <div class="pick-meta">${esc(p.sku)} · <span class="badge healthy" style="font-size:10px;padding:2px 6px;">${p.stock} in stock</span></div>
      <div class="pick-card-foot">
        <span class="pick-price">${money(p.price)}</span>
        <button class="pick-add-btn" aria-label="Add to cart">＋</button>
      </div>
    </div>
  `).join('');
}

function addToCart(productId) {
  const prod = state.products.find((p) => p.id === productId);
  if (!prod) return;

  if (prod.stock <= 0) {
    showToast(`'${prod.name}' is currently out of stock.`, 'error');
    return;
  }

  const existing = state.cart.find((c) => c.id === productId);
  if (existing) {
    if (existing.qty >= prod.stock) {
      showToast(`Cannot add more. Available stock limit is ${prod.stock}.`, 'error');
      return;
    }
    existing.qty++;
  } else {
    state.cart.push({
      id: prod.id,
      name: prod.name,
      price: prod.price,
      stock: prod.stock,
      qty: 1
    });
  }

  renderCart();
}

function changeCartQty(productId, delta) {
  const item = state.cart.find((c) => c.id === productId);
  const prod = state.products.find((p) => p.id === productId);
  if (!item || !prod) return;

  item.qty += delta;

  if (item.qty <= 0) {
    state.cart = state.cart.filter((c) => c.id !== productId);
  } else if (item.qty > prod.stock) {
    item.qty = prod.stock;
    showToast(`Maximum available stock is ${prod.stock}.`, 'warning');
  }

  renderCart();
}

function removeFromCart(productId) {
  state.cart = state.cart.filter((c) => c.id !== productId);
  renderCart();
}

function renderCart() {
  const countEl = $('#posCartItemCount');
  const listEl = $('#posCartItemsList');
  const totalCount = state.cart.reduce((sum, i) => sum + i.qty, 0);

  if (countEl) countEl.textContent = `${totalCount} item${totalCount === 1 ? '' : 's'}`;

  if (!state.cart.length) {
    listEl.innerHTML = `<div class="empty-state">Cart is empty.<br>Click items on the left to start billing.</div>`;
    $('#posSubtotalText').textContent = money(0);
    $('#posTaxText').textContent = money(0);
    $('#posGrandTotalText').textContent = money(0);
    return;
  }

  listEl.innerHTML = state.cart.map((item) => `
    <div class="cart-row">
      <div class="cart-item-info">
        <h5>${esc(item.name)}</h5>
        <span>${money(item.price)} × ${item.qty}</span>
        <div class="cart-qty-ctrl">
          <button class="qty-btn" onclick="changeCartQty(${item.id}, -1)">−</button>
          <span class="qty-val">${item.qty}</span>
          <button class="qty-btn" onclick="changeCartQty(${item.id}, 1)">＋</button>
          <button class="qty-btn" style="color:var(--red);margin-left:4px;" onclick="removeFromCart(${item.id})">✕</button>
        </div>
      </div>
      <div class="cart-item-total">${money(item.price * item.qty)}</div>
    </div>
  `).join('');

  // Calculations
  const subtotal = state.cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
  const discountInput = Math.max(0, Number($('#posDiscountInput')?.value) || 0);
  const discount = Math.min(subtotal, discountInput);
  const taxable = Math.max(0, subtotal - discount);
  const tax = Number((taxable * 0.05).toFixed(2));
  const grandTotal = Number((taxable + tax).toFixed(2));

  $('#posSubtotalText').textContent = money(subtotal);
  $('#posTaxText').textContent = money(tax);
  $('#posGrandTotalText').textContent = money(grandTotal);
}

$('#posClearCartBtn')?.addEventListener('click', () => {
  state.cart = [];
  renderCart();
});

$('#posDiscountInput')?.addEventListener('input', renderCart);
$('#posProductSearch')?.addEventListener('input', renderProductPicker);

// Complete Sale Transaction Submission
$('#posCompleteSaleBtn')?.addEventListener('click', async () => {
  if (!state.cart.length) {
    showToast('Add at least one product to the cart before checking out.', 'error');
    return;
  }

  const customerId = $('#posCustomerSelect')?.value || '';
  const paymentMethod = $('#posPaymentMethodSelect')?.value || 'cash';
  const discount = Math.max(0, Number($('#posDiscountInput')?.value) || 0);

  const payload = {
    customer_id: customerId ? Number(customerId) : null,
    payment_method: paymentMethod,
    discount,
    items: state.cart.map((c) => ({
      id: c.id,
      qty: c.qty
    }))
  };

  const btn = $('#posCompleteSaleBtn');
  btn.disabled = true;
  btn.textContent = 'Processing Transaction...';
  payload.idempotency_key = makeIdempotencyKey('sale', payload);

  try {
    const res = await API.post('sales.php', payload);
    if (res.success && res.data) {
      clearIdempotencyKey('sale');
      showToast(`Sale completed! Invoice ${res.data.invoice_no} generated.`);
      state.cart = [];
      if ($('#posDiscountInput')) $('#posDiscountInput').value = 0;
      renderCart();

      // Refresh database records
      await Promise.all([loadProducts(), loadSales(), loadAnalytics(), loadCustomers()]);

      // Open print invoice modal automatically
      showInvoiceModal(res.data.invoice_no);
    } else {
      showToast(res.message || 'Transaction failed.', 'error');
    }
  } finally {
    btn.disabled = false;
    btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg> Complete Sale & Generate Invoice`;
  }
});

function renderSalesHistory() {
  const tbody = $('#salesHistoryTableBody');
  if (!tbody) return;

  if (!state.sales.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="empty-state">No sales transactions found in ledger.</td></tr>`;
    return;
  }

  tbody.innerHTML = state.sales.map((s) => {
    const itemsSummary = Array.isArray(s.items)
      ? s.items.map((i) => `${esc(i.name)} × ${i.qty}`).join(', ')
      : '—';

    return `
      <tr>
        <td><b>${esc(s.invoice_no || s.id)}</b></td>
        <td>${esc(s.customer)}</td>
        <td class="cell-sub">${esc(s.date)}</td>
        <td><div class="cell-sub" style="max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${itemsSummary}</div></td>
        <td><span class="badge info">${esc(s.payment_method ? s.payment_method.toUpperCase() : 'CASH')}</span></td>
        <td><b>${money(s.total || s.grand_total)}</b></td>
        <td>
          <button class="btn btn-sm btn-outline" onclick="showInvoiceModal('${esc(s.invoice_no || s.id)}')">View Invoice</button>
        </td>
      </tr>
    `;
  }).join('');
}

// 5. Customers CRM
function renderCustomers() {
  const tbody = $('#customersTableBody');
  if (!tbody) return;

  if (!state.customers.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="empty-state">No registered customers found.</td></tr>`;
    return;
  }

  const actions = canManageRecords();
  tbody.innerHTML = state.customers.map((c) => `
    <tr>
      <td>
        <b>${esc(c.name)}</b>
      </td>
      <td>${esc(c.phone)}</td>
      <td>${esc(c.email || '—')}</td>
      <td class="cell-sub">${esc(c.address || '—')}</td>
      <td><span class="badge purple">${c.purchases} Orders</span></td>
      <td><b>${money(c.total_spent)}</b></td>
      <td>
        ${actions ? `<div class="table-actions">
          <button class="btn btn-sm btn-secondary" onclick="openCustomerModal(${c.id})">Edit</button>
          <button class="btn btn-sm btn-outline" style="color:var(--red);" onclick="deleteCustomer(${c.id})">Delete</button>
        </div>` : '<span class="cell-sub">Read only</span>'}
      </td>
    </tr>
  `).join('');
}

// 6. Suppliers
function renderSuppliers() {
  const tbody = $('#suppliersTableBody');
  if (!tbody) return;

  if (!state.suppliers.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="empty-state">No suppliers registered.</td></tr>`;
    return;
  }

  const actions = canManageRecords();
  tbody.innerHTML = state.suppliers.map((s) => `
    <tr>
      <td>
        <b>${esc(s.company)}</b>
        <div class="cell-sub">${esc(s.address || '—')}</div>
      </td>
      <td>${esc(s.name)}</td>
      <td>${esc(s.phone)}</td>
      <td>${esc(s.email || '—')}</td>
      <td><span class="badge blue">${s.purchase_count || s.products || 0} POs</span></td>
      <td><b>${money(s.total_purchased || 0)}</b></td>
      <td>
        ${actions ? `<div class="table-actions">
          <button class="btn btn-sm btn-secondary" onclick="openSupplierModal(${s.id})">Edit</button>
          <button class="btn btn-sm btn-outline" style="color:var(--red);" onclick="deleteSupplier(${s.id})">Delete</button>
        </div>` : '<span class="cell-sub">Read only</span>'}
      </td>
    </tr>
  `).join('');
}

// 7. Reports & Analytics
function renderReports() {
  if (!state.analytics) return;
  const summary = state.analytics.summary || {};

  $('#reportRevenue').textContent = money(summary.total_revenue);
  $('#reportProfit').textContent = summary.gross_profit_is_verified
    ? money(summary.gross_profit)
    : `~${money(summary.gross_profit_estimate)}`;
  $('#reportInvValue').textContent = money(summary.inventory_retail_value);
  $('#reportAlertCount').textContent = (summary.low_stock_count || 0) + (summary.out_of_stock_count || 0);

  // Top Products List
  const topList = $('#reportTopProductsList');
  const topProducts = state.analytics.top_products || [];
  const maxQty = Math.max(...topProducts.map((p) => Number(p.qty_sold)), 1);

  if (topList) {
    if (!topProducts.length) {
      topList.innerHTML = `<div class="empty-state">No sales transactions available to rank products.</div>`;
    } else {
      topList.innerHTML = topProducts.map((p, idx) => {
        const widthPct = Math.round((Number(p.qty_sold) / maxQty) * 100);
        return `
          <div class="rank-row">
            <div class="rank-num">${idx + 1}</div>
            <div class="rank-info">
              <div class="rank-name">
                <span><b>${esc(p.name)}</b> (${esc(p.sku)})</span>
                <b>${p.qty_sold} units · ${money(p.revenue)}</b>
              </div>
              <div class="progress-track">
                <div class="progress-bar" style="width:${widthPct}%"></div>
              </div>
            </div>
          </div>
        `;
      }).join('');
    }
  }

  // Category Distribution List
  const catList = $('#reportCategoryList');
  const cats = state.analytics.category_breakdown || [];
  const totalUnits = (summary.total_units || 1);

  if (catList) {
    if (!cats.length) {
      catList.innerHTML = `<div class="empty-state">No categories available.</div>`;
    } else {
      catList.innerHTML = cats.map((c) => {
        const pct = Math.round((Number(c.total_stock) / totalUnits) * 100);
        return `
          <div class="rank-row">
            <div class="rank-info">
              <div class="rank-name">
                <span><b>${esc(c.category)}</b> (${c.product_count} items)</span>
                <b>${c.total_stock} units (${pct}%)</b>
              </div>
              <div class="progress-track">
                <div class="progress-bar" style="width:${pct}%;background:var(--secondary);"></div>
              </div>
            </div>
          </div>
        `;
      }).join('');
    }
  }
}

// =========================================================================
// MODALS & FORMS
// =========================================================================

// Product Modal (Add / Edit)
function openProductModal(id = null) {
  state.editingProductId = id;
  const p = id ? state.products.find((x) => x.id === id) : {
    name: '',
    sku: '',
    barcode: '',
    category: 'Electronics',
    cost_price: '',
    price: '',
    stock: '',
    min: 5,
    unit: 'pcs'
  };

  openModal(`
    <div class="modal-header">
      <div>
        <h3>${id ? 'Edit Product' : 'Add New Product'}</h3>
        <p>${DEMO_MODE ? 'Sample data changes are saved in this browser only.' : 'Ensure SKU is unique. Data will be saved directly into MySQL database.'}</p>
      </div>
      <button class="modal-close-btn" onclick="closeModal()">✕</button>
    </div>
    <form id="productForm">
      <div class="form-grid">
        <div class="form-group full-width">
          <label class="form-label">Product Name <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" required value="${esc(p.name)}" placeholder="e.g. Wireless Ergonomic Mouse">
        </div>
        <div class="form-group">
          <label class="form-label">SKU (Unique Code) <span class="req">*</span></label>
          <input type="text" name="sku" class="form-control" required value="${esc(p.sku)}" placeholder="e.g. MS-204">
        </div>
        <div class="form-group">
          <label class="form-label">Barcode / UPC</label>
          <input type="text" name="barcode" class="form-control" value="${esc(p.barcode || '')}" placeholder="Optional barcode digits">
        </div>
        <div class="form-group">
          <label class="form-label">Category <span class="req">*</span></label>
          <input type="text" name="category" list="categoryOptionsList" class="form-control" required value="${esc(p.category || 'General')}" placeholder="Select or type new category">
          <datalist id="categoryOptionsList">
            ${state.categories.map((c) => `<option value="${esc(c.name)}"></option>`).join('')}
          </datalist>
        </div>
        <div class="form-group">
          <label class="form-label">Unit of Measure</label>
          <input type="text" name="unit" class="form-control" value="${esc(p.unit || 'pcs')}" placeholder="pcs, kg, box">
        </div>
        <div class="form-group">
          <label class="form-label">Cost Price (₹)</label>
          <input type="number" name="cost_price" class="form-control" min="0" step="0.01" value="${p.cost_price || 0}">
        </div>
        <div class="form-group">
          <label class="form-label">Selling Price (₹) <span class="req">*</span></label>
          <input type="number" name="price" class="form-control" min="0" step="0.01" required value="${p.price || ''}">
        </div>
        <div class="form-group">
          <label class="form-label">Current Stock <span class="req">*</span></label>
          <input type="number" name="stock" class="form-control" min="0" required value="${p.stock !== undefined ? p.stock : 10}">
        </div>
        <div class="form-group">
          <label class="form-label">Min Reorder Level <span class="req">*</span></label>
          <input type="number" name="min" class="form-control" min="0" required value="${p.min !== undefined ? p.min : 5}">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">${id ? 'Save Changes' : 'Create Product'}</button>
      </div>
    </form>
  `);

  $('#productForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = {
      name: fd.get('name').trim(),
      sku: fd.get('sku').trim().toUpperCase(),
      barcode: fd.get('barcode').trim(),
      category: fd.get('category').trim(),
      unit: fd.get('unit').trim() || 'pcs',
      cost_price: Number(fd.get('cost_price')) || 0,
      price: Number(fd.get('price')),
      stock: Number(fd.get('stock')),
      min: Number(fd.get('min'))
    };

    if (state.editingProductId) {
      payload.id = state.editingProductId;
      const res = await API.put('products.php', payload);
      if (res.success) {
        showToast('Product updated successfully.');
        closeModal();
        await Promise.all([loadProducts(), loadAnalytics(), loadCategories()]);
      } else {
        showToast(res.message, 'error');
      }
    } else {
      const res = await API.post('products.php', payload);
      if (res.success) {
        showToast('New product added to catalog.');
        closeModal();
        await Promise.all([loadProducts(), loadAnalytics(), loadCategories()]);
      } else {
        showToast(res.message, 'error');
      }
    }
  });
}

function deleteProduct(id) {
  const p = state.products.find((x) => x.id === id);
  if (!p) return;

  showConfirm(
    'Delete Product',
    `Are you sure you want to delete '${p.name}' (${p.sku})? If it has historical sales, it will be deactivated to safeguard ledger integrity.`,
    async () => {
      const res = await API.delete('products.php', { id });
      if (res.success) {
        showToast(res.message);
        await Promise.all([loadProducts(), loadAnalytics()]);
      } else {
        showToast(res.message, 'error');
      }
    }
  );
}

// Quick Stock Adjust Modal
function openQuickAdjustModal(productId) {
  const p = state.products.find((x) => x.id === productId);
  if (!p) return;

  openModal(`
    <div class="modal-header">
      <div>
        <h3>Adjust Stock Quantity</h3>
        <p>Set a new stock level or record reason for manual inventory audit.</p>
      </div>
      <button class="modal-close-btn" onclick="closeModal()">✕</button>
    </div>
    <form id="stockAdjustForm">
      <div class="form-grid">
        <div class="form-group full-width">
          <label class="form-label">Product</label>
          <input type="text" class="form-control" value="${esc(p.name)} (${esc(p.sku)})" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Current Stock Balance</label>
          <input type="text" class="form-control" value="${p.stock}" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">New Stock Quantity <span class="req">*</span></label>
          <input type="number" name="new_stock" class="form-control" min="0" required value="${p.stock}">
        </div>
        <div class="form-group full-width">
          <label class="form-label">Adjustment Reason / Notes</label>
          <input type="text" name="notes" class="form-control" value="Manual stock physical recount" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Stock</button>
      </div>
    </form>
  `);

  $('#stockAdjustForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const newStock = Number(fd.get('new_stock'));
    const notes = fd.get('notes').trim();

    const res = await API.post('stock.php', {
      id: productId,
      new_stock: newStock,
      notes
    });

    if (res.success) {
      showToast(res.message);
      closeModal();
      await Promise.all([loadProducts(), loadAnalytics()]);
    } else {
      showToast(res.message, 'error');
    }
  });
}

// Restock / Purchase Order Modal
function openRestockModal() {
  openModal(`
    <div class="modal-header">
      <div>
        <h3>Supplier Restock / Purchase Order</h3>
        <p>Create an order first. Inventory changes only after a separate receipt confirmation.</p>
      </div>
      <button class="modal-close-btn" onclick="closeModal()">✕</button>
    </div>
    <form id="restockForm">
      <div class="form-grid">
        <div class="form-group full-width">
          <label class="form-label">Supplier <span class="req">*</span></label>
          <select name="supplier_id" class="form-control" required id="restockSupplierSelect">
            ${state.suppliers.map((s) => `<option value="${s.id}">${esc(s.company)} (${esc(s.name)})</option>`).join('')}
          </select>
        </div>
        <div class="form-group full-width">
          <label class="form-label">Product to Restock <span class="req">*</span></label>
          <select name="product_id" class="form-control" required id="restockProductSelect">
            ${state.products.map((p) => `<option value="${p.id}">${esc(p.name)} (${esc(p.sku)}) · In Stock: ${p.stock}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Quantity to Add <span class="req">*</span></label>
          <input type="number" name="quantity" class="form-control" min="1" value="20" required>
        </div>
        <div class="form-group">
          <label class="form-label">Unit Cost Price (₹)</label>
          <input type="number" name="unit_cost" class="form-control" min="0" step="0.01" placeholder="Leave empty for default cost">
        </div>
        <div class="form-group full-width">
          <label class="form-label">Purchase Notes</label>
          <input type="text" name="notes" class="form-control" value="Direct supplier restocking batch">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Purchase Order</button>
      </div>
    </form>
  `);

  $('#restockForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const supplierId = Number(fd.get('supplier_id'));
    const productId = Number(fd.get('product_id'));
    const qty = Number(fd.get('quantity'));
    const unitCost = Number(fd.get('unit_cost')) || undefined;
    const notes = fd.get('notes').trim();

    const payload = {
      supplier_id: supplierId,
      notes,
      items: [{
        product_id: productId,
        quantity: qty,
        unit_cost: unitCost
      }]
    };
    payload.idempotency_key = makeIdempotencyKey('purchase', payload);
    const submitButton = e.currentTarget.querySelector('[type="submit"]');
    submitButton.disabled = true;
    try {
      const res = await API.post('purchases.php', payload);
      if (res.success) {
        clearIdempotencyKey('purchase');
        showToast(`Purchase order ${res.data?.po_number || ''} created. Confirm receipt in Purchase Orders when goods physically arrive.`);
        closeModal();
        await Promise.all([loadPurchases(), loadSuppliers()]);
      } else {
        showToast(res.message, 'error');
      }
    } finally {
      if (submitButton.isConnected) submitButton.disabled = false;
    }
  });
}

// External Fake API Importer Modal (Requirement 11)
async function openFakeApiModal() {
  openModal(`
    <div class="modal-header">
      <div>
        <h3>Import from External Mock/Fake API</h3>
        <p>${DEMO_MODE ? 'Fictional sample catalog. Add selected items to this browser demo.' : 'Staged external catalogue from Fake API. Review and click "Import to MySQL" to store verified records.'}</p>
      </div>
      <button class="modal-close-btn" onclick="closeModal()">✕</button>
    </div>
    <div id="fakeApiCatalogArea" style="min-height:200px;">
      <div class="empty-state">${DEMO_MODE ? 'Loading built-in sample catalog...' : 'Connecting to External Product Catalog API...'}</div>
    </div>
  `);

  const res = await API.get('fake-api.php');
  const area = $('#fakeApiCatalogArea');
  if (!area) return;

  if (res.success && res.data?.catalog) {
    const list = res.data.catalog;
    area.innerHTML = `
      <div class="system-alert-banner" style="margin-bottom:16px;">
        <div>
          <b>Source:</b> ${esc(res.data.source_origin)} (${esc(res.data.source_type)})<br>
          <small>${esc(res.data.note)}</small>
        </div>
      </div>
      <div class="table-responsive" style="max-height:360px;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Staged Item</th>
              <th>Category</th>
              <th>Suggested SKU</th>
              <th>Selling Price</th>
              <th>Stock</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            ${list.map((item, idx) => `
              <tr id="fakeRow_${idx}">
                <td>
                  <b>${esc(item.name)}</b>
                  <div class="cell-sub"><span class="badge info">External Demo</span></div>
                </td>
                <td>${esc(item.category)}</td>
                <td><code>${esc(item.suggested_sku)}</code></td>
                <td><b>${money(item.selling_price)}</b></td>
                <td>${item.stock_quantity}</td>
                <td>
                  <button class="btn btn-sm btn-primary" onclick="importSingleFakeItem(${idx})">
                    ${DEMO_MODE ? 'Add to demo' : 'Import to MySQL'}
                  </button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal()">Close</button>
      </div>
    `;

    window.stagedFakeCatalog = list;
  } else {
    area.innerHTML = `<div class="empty-state" style="color:var(--red);">Failed to connect to External Mock API: ${esc(res.message)}</div>`;
  }
}

window.importSingleFakeItem = async function(index) {
  const item = window.stagedFakeCatalog?.[index];
  if (!item) return;

  const row = $(`#fakeRow_${index}`);
  if (row) row.style.opacity = '0.5';

  const res = await API.post('fake-api.php', item);
  if (res.success) {
    showToast(DEMO_MODE ? `'${item.name}' added to the browser demo.` : `'${item.name}' imported and stored into MySQL!`);
    if (row) {
      row.innerHTML = `<td colspan="6" style="color:var(--green);font-weight:600;padding:12px 16px;">✓ ${DEMO_MODE ? 'Added' : 'Successfully imported'} as product ID #${res.data?.id} (${esc(res.data?.sku)}) ${DEMO_MODE ? 'to this browser demo' : 'into MySQL'}.</td>`;
    }
    await Promise.all([loadProducts(), loadCategories(), loadAnalytics()]);
  } else {
    showToast(res.message, 'error');
    if (row) row.style.opacity = '1';
  }
};

// Customer Modal (Add / Edit)
function openCustomerModal(id = null) {
  state.editingCustomerId = id;
  const c = id ? state.customers.find((x) => x.id === id) : { name: '', phone: '', email: '', address: '' };

  openModal(`
    <div class="modal-header">
      <div>
        <h3>${id ? 'Edit Customer' : 'Add New Customer'}</h3>
        <p>Save customer contact details for billing and CRM purchase tracking.</p>
      </div>
      <button class="modal-close-btn" onclick="closeModal()">✕</button>
    </div>
    <form id="customerForm">
      <div class="form-grid">
        <div class="form-group full-width">
          <label class="form-label">Full Name <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" required value="${esc(c.name)}" placeholder="e.g. Priya Sharma">
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number <span class="req">*</span></label>
          <input type="tel" name="phone" class="form-control" required value="${esc(c.phone)}" placeholder="e.g. 9876543210">
        </div>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" value="${esc(c.email || '')}" placeholder="priya@example.com">
        </div>
        <div class="form-group full-width">
          <label class="form-label">Address / Notes</label>
          <textarea name="address" class="form-control" rows="2" placeholder="Street, City, State">${esc(c.address || '')}</textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">${id ? 'Save Changes' : 'Save Customer'}</button>
      </div>
    </form>
  `);

  $('#customerForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = {
      name: fd.get('name').trim(),
      phone: fd.get('phone').trim(),
      email: fd.get('email').trim(),
      address: fd.get('address').trim()
    };

    if (state.editingCustomerId) {
      payload.id = state.editingCustomerId;
      const res = await API.put('customers.php', payload);
      if (res.success) {
        showToast('Customer profile updated.');
        closeModal();
        await loadCustomers();
      } else {
        showToast(res.message, 'error');
      }
    } else {
      const res = await API.post('customers.php', payload);
      if (res.success) {
        showToast('Customer created successfully.');
        closeModal();
        await loadCustomers();
      } else {
        showToast(res.message, 'error');
      }
    }
  });
}

function deleteCustomer(id) {
  const c = state.customers.find((x) => x.id === id);
  if (!c) return;

  showConfirm(
    'Delete Customer',
    `Are you sure you want to delete customer '${c.name}'?`,
    async () => {
      const res = await API.delete('customers.php', { id });
      if (res.success) {
        showToast(res.message);
        await loadCustomers();
      } else {
        showToast(res.message, 'error');
      }
    }
  );
}

// Supplier Modal (Add / Edit)
function openSupplierModal(id = null) {
  state.editingSupplierId = id;
  const s = id ? state.suppliers.find((x) => x.id === id) : { name: '', company: '', phone: '', email: '', address: '' };

  openModal(`
    <div class="modal-header">
      <div>
        <h3>${id ? 'Edit Supplier' : 'Add New Supplier'}</h3>
        <p>Register vendor contact details and procurement organization.</p>
      </div>
      <button class="modal-close-btn" onclick="closeModal()">✕</button>
    </div>
    <form id="supplierForm">
      <div class="form-grid">
        <div class="form-group full-width">
          <label class="form-label">Company / Business Name <span class="req">*</span></label>
          <input type="text" name="company" class="form-control" required value="${esc(s.company)}" placeholder="e.g. TechWorld Supplies Pvt. Ltd.">
        </div>
        <div class="form-group">
          <label class="form-label">Contact Person <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" required value="${esc(s.name)}" placeholder="e.g. Ramesh Chandra">
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number <span class="req">*</span></label>
          <input type="tel" name="phone" class="form-control" required value="${esc(s.phone)}" placeholder="e.g. 9000011111">
        </div>
        <div class="form-group full-width">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" value="${esc(s.email || '')}" placeholder="contact@techworld.example">
        </div>
        <div class="form-group full-width">
          <label class="form-label">Warehouse Address</label>
          <textarea name="address" class="form-control" rows="2" placeholder="Industrial Area, City, State">${esc(s.address || '')}</textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">${id ? 'Save Changes' : 'Save Supplier'}</button>
      </div>
    </form>
  `);

  $('#supplierForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = {
      company: fd.get('company').trim(),
      name: fd.get('name').trim(),
      phone: fd.get('phone').trim(),
      email: fd.get('email').trim(),
      address: fd.get('address').trim()
    };

    if (state.editingSupplierId) {
      payload.id = state.editingSupplierId;
      const res = await API.put('suppliers.php', payload);
      if (res.success) {
        showToast('Supplier profile updated.');
        closeModal();
        await loadSuppliers();
      } else {
        showToast(res.message, 'error');
      }
    } else {
      const res = await API.post('suppliers.php', payload);
      if (res.success) {
        showToast('Supplier registered.');
        closeModal();
        await loadSuppliers();
      } else {
        showToast(res.message, 'error');
      }
    }
  });
}

function deleteSupplier(id) {
  const s = state.suppliers.find((x) => x.id === id);
  if (!s) return;

  showConfirm(
    'Delete Supplier',
    `Are you sure you want to remove supplier '${s.company}'?`,
    async () => {
      const res = await API.delete('suppliers.php', { id });
      if (res.success) {
        showToast(res.message);
        await loadSuppliers();
      } else {
        showToast(res.message, 'error');
      }
    }
  );
}

// Printable Invoice Modal
async function showInvoiceModal(invoiceNo) {
  const res = await API.get('sales.php', { invoice_no: invoiceNo });

  if (!res.success || !res.data) {
    showToast('Could not load invoice details.', 'error');
    return;
  }

  const s = res.data;
  const items = s.items || [];

  const invoiceHtml = `
    <div class="invoice-card" id="invoiceCardBox">
      <div class="invoice-header-row">
        <div>
          <div class="invoice-brand-title">SumYam Retail</div>
          <div class="invoice-sub">Retail Management & Inventory Operations</div>
          <div class="invoice-sub" style="font-size:11px;margin-top:2px;">Maintained by Saurav Shah Teli</div>
        </div>
        <div class="invoice-meta-info">
          <strong>${esc(s.invoice_no)}</strong>
          <div>Date: ${esc(s.sale_date || s.date)}</div>
          <div>Customer: <b>${esc(s.customer_name || s.customer)}</b></div>
          <div>Payment: ${esc((s.payment_method || 'cash').toUpperCase())}</div>
        </div>
      </div>

      <table class="invoice-items-table">
        <thead>
          <tr>
            <th style="text-align:left;">Item & SKU</th>
            <th style="text-align:right;">Unit Price</th>
            <th style="text-align:center;">Qty</th>
            <th style="text-align:right;">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          ${items.map((i) => `
            <tr>
              <td>
                <b>${esc(i.name || i.product_name)}</b>
                ${i.sku ? `<div style="font-size:10px;color:#64748b;">SKU: ${esc(i.sku)}</div>` : ''}
              </td>
              <td style="text-align:right;">${money(i.price || i.unit_price)}</td>
              <td style="text-align:center;">${i.qty || i.quantity}</td>
              <td style="text-align:right;"><b>${money(i.subtotal)}</b></td>
            </tr>
          `).join('')}
        </tbody>
      </table>

      <div class="invoice-summary-box">
        <div class="invoice-summary-row">
          <span>Subtotal</span>
          <b>${money(s.subtotal)}</b>
        </div>
        ${s.discount > 0 ? `
          <div class="invoice-summary-row" style="color:var(--green-text);">
            <span>Discount Applied</span>
            <b>−${money(s.discount)}</b>
          </div>
        ` : ''}
        <div class="invoice-summary-row">
          <span>GST / Tax (${s.tax_rate || 5}%)</span>
          <b>${money(s.tax_amount)}</b>
        </div>
        <div class="invoice-summary-row total">
          <span>Grand Total</span>
          <b>${money(s.grand_total || s.total)}</b>
        </div>
      </div>

      <div class="invoice-footer-note">
        Thank you for your business! · SumYam Retail · Generated on ${new Date().toLocaleString('en-IN')}
      </div>
    </div>
  `;

  // Place into print area for browser print
  const printArea = $('#invoicePrintArea');
  if (printArea) printArea.innerHTML = invoiceHtml;

  openModal(`
    <div class="modal-header">
      <div>
        <h3>Tax Invoice: ${esc(s.invoice_no)}</h3>
        <p>${DEMO_MODE ? 'Fictional demo invoice saved in this browser' : 'Verified sales record stored in MySQL database'}</p>
      </div>
      <button class="modal-close-btn" onclick="closeModal()">✕</button>
    </div>
    ${invoiceHtml}
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="window.print()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" width="16" height="16"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Invoice
      </button>
      <button class="btn btn-primary" onclick="closeModal()">Close</button>
    </div>
  `);
}

// =========================================================================
// EXPORTING DATA (CSV & JSON)
// =========================================================================
$('#exportJsonBtn')?.addEventListener('click', () => {
  const exportPayload = {
    exported_at: new Date().toISOString(),
    system: 'SumYam Retail',
    maintainer: 'Saurav Shah Teli',
    products: state.products,
    categories: state.categories,
    customers: state.customers,
    suppliers: state.suppliers,
    sales: state.sales
  };

  const blob = new Blob([JSON.stringify(exportPayload, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `sumyam-retail-export-${new Date().toISOString().slice(0, 10)}.json`;
  a.click();
  URL.revokeObjectURL(url);
  showToast(DEMO_MODE ? 'Demo records exported to JSON.' : 'Database records exported to JSON.');
});

$('#exportCsvBtn')?.addEventListener('click', () => {
  if (!state.products.length) {
    showToast('No products available to export.', 'warning');
    return;
  }

  const headers = ['ID', 'Name', 'SKU', 'Category', 'Cost Price', 'Selling Price', 'Stock', 'Min Stock', 'Unit', 'Status'];
  const rows = state.products.map((p) => [
    p.id,
    `"${p.name.replace(/"/g, '""')}"`,
    `"${p.sku}"`,
    `"${p.category}"`,
    p.cost_price,
    p.price,
    p.stock,
    p.min,
    p.unit,
    p.stock_status
  ]);

  const csvContent = [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `sumyam-retail-products-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  showToast('Products catalog exported to CSV.');
});

// =========================================================================
// EVENT LISTENERS & FILTERING
// =========================================================================
$('#productSearchInput')?.addEventListener('input', () => {
  clearTimeout(window.searchDebounce);
  window.searchDebounce = setTimeout(loadProducts, 250);
});

$('#productCategoryFilter')?.addEventListener('change', loadProducts);
$('#productStockFilter')?.addEventListener('change', loadProducts);
$('#productSortFilter')?.addEventListener('change', loadProducts);

$('#customerSearchInput')?.addEventListener('input', () => {
  clearTimeout(window.custSearchDebounce);
  window.custSearchDebounce = setTimeout(loadCustomers, 250);
});

$('#supplierSearchInput')?.addEventListener('input', () => {
  clearTimeout(window.supSearchDebounce);
  window.supSearchDebounce = setTimeout(loadSuppliers, 250);
});

// Global Search (in topbar)
$('#globalSearchInput')?.addEventListener('input', (e) => {
  const query = e.target.value.trim().toLowerCase();
  if (!query) return;

  if (state.activeSection !== 'products' && state.activeSection !== 'sales') {
    navigate('products');
  }

  const prodInput = $('#productSearchInput');
  if (prodInput) {
    prodInput.value = query;
    loadProducts();
  }
});

// Button Bindings
$('#openAddProductModalBtn')?.addEventListener('click', () => openProductModal());
$('#openAddCustomerModalBtn')?.addEventListener('click', () => openCustomerModal());
$('#openAddSupplierModalBtn')?.addEventListener('click', () => openSupplierModal());
$('#openPurchaseRestockBtn')?.addEventListener('click', () => openRestockModal());
$('#heroRestockBtn')?.addEventListener('click', () => openRestockModal());
$('#openRestockModalBtn')?.addEventListener('click', () => openRestockModal());
$('#importFakeApiBtn')?.addEventListener('click', () => openFakeApiModal());

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeModal();
  if (e.ctrlKey && e.key.toLowerCase() === 'n') {
    e.preventDefault();
    navigate('sales');
  }
});

// =========================================================================
// INITIAL APPLICATION BOOTSTRAP
// =========================================================================
async function initApp() {
  if (DEMO_MODE) {
    document.body.classList.remove('auth-locked');
    $('#userName').textContent = 'Static demo';
    $('#userRole').textContent = 'Sample data';
    $('#userAvatar').textContent = 'SD';
    $('#logoutBtn').hidden = true;
    applyRolePermissions();
    await loadAllData();
    return;
  }
  if (!(await initializeSession())) return;
  await loadAllData();
}

initApp();
