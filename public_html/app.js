(function () {
  const menuToggle = document.querySelector('.mobile-menu-toggle');
  const sidebarMenu = document.querySelector('#sidebar-menu');
  if (menuToggle && sidebarMenu) {
    menuToggle.addEventListener('click', () => {
      const isOpen = document.body.classList.toggle('mobile-menu-open');
      menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    sidebarMenu.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        document.body.classList.remove('mobile-menu-open');
        menuToggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  const searchInputs = document.querySelectorAll('.filter-bar input[placeholder]');
  searchInputs.forEach((input) => {
    input.addEventListener('input', () => {
      const panel = input.closest('.panel');
      const rows = panel ? panel.querySelectorAll('tbody tr') : [];
      const query = input.value.trim().toLowerCase();
      rows.forEach((row) => {
        row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
      });
    });
  });

  const syncRegionalOptions = (areaSelect) => {
    const form = areaSelect.closest('form');
    const regionalSelect = form ? form.querySelector('.js-regional-select') : null;
    if (!regionalSelect) {
      return;
    }
    const selectedArea = areaSelect.value;
    let currentStillVisible = false;
    regionalSelect.querySelectorAll('option').forEach((option) => {
      const optionArea = option.dataset.area || '';
      const visible = option.value === '' || optionArea === '' || optionArea === selectedArea;
      option.hidden = !visible;
      option.disabled = !visible;
      if (visible && option.selected) {
        currentStillVisible = true;
      }
    });
    if (!currentStillVisible) {
      regionalSelect.value = '';
    }
  };

  document.querySelectorAll('.js-area-select').forEach((areaSelect) => {
    syncRegionalOptions(areaSelect);
    areaSelect.addEventListener('change', () => syncRegionalOptions(areaSelect));
  });

  const syncClosingStatusTone = (select) => {
    const isClosing = select.value.trim().toLowerCase() === 'closing';
    select.classList.toggle('is-closing', isClosing);
    if (isClosing) {
      select.style.borderColor = '#93c5fd';
      select.style.background = '#dbeafe';
      select.style.color = '#1e3a8a';
      select.style.fontWeight = '700';
    } else {
      select.style.borderColor = '';
      select.style.background = '';
      select.style.color = '';
      select.style.fontWeight = '';
    }
  };
  document.querySelectorAll('.compact-status-select').forEach((select) => {
    syncClosingStatusTone(select);
    select.addEventListener('change', () => syncClosingStatusTone(select));
  });

  document.addEventListener('click', (event) => {
    const printRekapButton = event.target.closest('.js-print-rekap');
    if (printRekapButton) {
      window.print();
      return;
    }

    const safeCopyButton = event.target.closest('.whatsapp-safe-copy');
    if (safeCopyButton) {
      const textarea = document.querySelector('.whatsapp-safe-text');
      if (!textarea) {
        return;
      }
      textarea.focus();
      textarea.select();
      try {
        document.execCommand('copy');
        safeCopyButton.textContent = 'Tersalin';
      } catch (error) {
        safeCopyButton.textContent = 'Blok lalu Ctrl+C';
      }
      window.setTimeout(() => {
        safeCopyButton.textContent = 'Copy Text Otomatis';
      }, 1800);
    }
  });
})();
