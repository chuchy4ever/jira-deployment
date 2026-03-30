import '../css/app.css';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import * as bootstrap from 'bootstrap';
import naja from 'naja';

// URLs from data attributes
const URLS = {
	refresh: document.body.dataset.urlRefresh,
	forceRefresh: document.body.dataset.urlForceRefresh,
};

// Naja init
naja.initialize();

// Polling
let pollInterval;
function startPolling() {
	pollInterval = setInterval(() => {
		naja.makeRequest('GET', URLS.refresh, null, { history: false, unique: true })
			.then(() => {
				document.getElementById('last-update').textContent =
					'Aktualizováno: ' + new Date().toLocaleTimeString('cs-CZ');
			})
			.catch(() => {});
	}, 10000);
}
startPolling();

// Force refresh from Jira + GitHub (clears cache)
window.forceRefresh = function () {
	const btn = document.getElementById('btn-force-refresh');
	btn.disabled = true;
	btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Načítám...';

	naja.makeRequest('GET', URLS.forceRefresh, null, { history: false })
		.then(() => {
			document.getElementById('last-update').textContent =
				'Aktualizováno: ' + new Date().toLocaleTimeString('cs-CZ');
		})
		.catch(() => {})
		.finally(() => {
			btn.disabled = false;
			btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Obnovit z Jiry';
		});
};

// Auto-hide flash messages after 5s
naja.addEventListener('complete', () => {
	setTimeout(() => {
		document.querySelectorAll('#flash-container .alert').forEach(el => {
			const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
			bsAlert.close();
		});
	}, 5000);
});

// Confirm modal
let pendingUrl = null;
window.confirmAction = function (url, title, message) {
	pendingUrl = url;
	document.getElementById('confirmModalTitle').textContent = title;
	document.getElementById('confirmModalBody').textContent = message;
	const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
	modal.show();
};

// Confirm from data attributes (avoids Latte escaping issues)
window.confirmFromData = function (el) {
	window.confirmAction(
		el.dataset.confirmUrl,
		el.dataset.confirmTitle,
		el.dataset.confirmMessage
	);
};

document.getElementById('confirmModalOk').addEventListener('click', () => {
	if (pendingUrl) {
		naja.makeRequest('GET', pendingUrl, null, { history: false });
		pendingUrl = null;
	}
	bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
});
