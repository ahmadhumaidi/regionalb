(function () {
  document.querySelectorAll('.schedule-modal').forEach((modal) => {
    if (modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }
  });

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

  document.querySelectorAll('.table-wrap table').forEach((table) => {
    const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    if (!headers.length) {
      return;
    }
    table.querySelectorAll('tbody tr').forEach((row) => {
      Array.from(row.children).forEach((cell, index) => {
        if (cell.tagName.toLowerCase() !== 'td' || cell.dataset.label) {
          return;
        }
        cell.dataset.label = headers[index] || '';
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

  document.querySelectorAll('[data-checklist-toggle]').forEach((checkbox) => {
    const status = checkbox.closest('.checklist-jobdesk-check')?.querySelector('.checklist-jobdesk-status');
    if (!status) {
      return;
    }
    checkbox.addEventListener('change', () => {
      status.textContent = checkbox.checked ? 'Lengkap' : 'Belum Lengkap';
      status.classList.toggle('is-complete', checkbox.checked);
    });
  });

  document.querySelectorAll('[data-checklist-pair-group]').forEach((group) => {
    const boxes = Array.from(group.querySelectorAll('[data-checklist-pair]'));
    boxes.forEach((box) => {
      box.addEventListener('change', () => {
        if (box.checked) {
          boxes.forEach((other) => {
            if (other !== box) {
              other.checked = false;
            }
          });
        } else {
          box.checked = true;
        }
      });
    });
  });

  document.querySelectorAll('.js-area-select').forEach((areaSelect) => {
    syncRegionalOptions(areaSelect);
    areaSelect.addEventListener('change', () => syncRegionalOptions(areaSelect));
  });

  const fillSocialPic = (campusSelect) => {
    const form = campusSelect.closest('form');
    if (!form) {
      return;
    }
    const selected = campusSelect.selectedOptions && campusSelect.selectedOptions[0] ? campusSelect.selectedOptions[0] : null;
    const picName = selected ? selected.dataset.pic || '' : '';
    const picPhone = selected ? selected.dataset.phone || '' : '';
    const instagram = selected ? selected.dataset.instagram || '' : '';
    const business = selected ? selected.dataset.business || '' : '';
    const connection = selected ? selected.dataset.connection || '' : '';
    const notes = selected ? selected.dataset.notes || '' : '';
    const nameInput = form.querySelector('.js-social-pic-name');
    const phoneInput = form.querySelector('.js-social-pic-phone');
    const instagramInput = form.querySelector('.js-social-instagram');
    const businessInput = form.querySelector('.js-social-business');
    const connectionInput = form.querySelector('.js-social-connection');
    const notesInput = form.querySelector('.js-social-notes');
    if (nameInput && picName !== '') {
      nameInput.value = picName;
    }
    if (phoneInput) {
      phoneInput.value = picPhone;
    }
    if (instagramInput) {
      instagramInput.value = instagram;
      instagramInput.readOnly = instagram !== '';
    }
    if (businessInput) {
      businessInput.value = business;
    }
    if (connectionInput && connection !== '') {
      connectionInput.value = connection;
    }
    if (notesInput) {
      notesInput.value = notes;
    }
  };

  document.querySelectorAll('.js-social-campus-select').forEach((campusSelect) => {
    fillSocialPic(campusSelect);
    campusSelect.addEventListener('change', () => fillSocialPic(campusSelect));
  });

  const syncCampusOptions = (regionalSelect) => {
    const form = regionalSelect.closest('form');
    const campusSelect = form ? form.querySelector('.js-campus-filter-select') : null;
    if (!campusSelect) {
      return;
    }
    const selectedRegional = regionalSelect.value;
    let currentStillVisible = false;
    campusSelect.querySelectorAll('option').forEach((option) => {
      const optionRegional = option.dataset.regional || '';
      const visible = option.value === '' || selectedRegional === '' || optionRegional === selectedRegional;
      option.hidden = !visible;
      option.disabled = !visible;
      if (visible && option.selected) {
        currentStillVisible = true;
      }
    });
    if (!currentStillVisible) {
      campusSelect.value = '';
    }
    if (campusSelect.classList.contains('js-social-campus-select')) {
      fillSocialPic(campusSelect);
    }
  };

  document.querySelectorAll('.js-campus-regional-select').forEach((regionalSelect) => {
    syncCampusOptions(regionalSelect);
    regionalSelect.addEventListener('change', () => syncCampusOptions(regionalSelect));
  });

  const syncPostInstagramLink = (accountSelect) => {
    const form = accountSelect.closest('form');
    const link = form ? form.querySelector('.js-post-instagram-link') : null;
    const connectLink = form ? form.querySelector('.js-post-connect-link') : null;
    if (!link && !connectLink) {
      return;
    }
    const selected = accountSelect.selectedOptions && accountSelect.selectedOptions[0] ? accountSelect.selectedOptions[0] : null;
    const username = selected ? selected.dataset.instagram || '' : '';
    const connectUrl = selected ? selected.dataset.connectUrl || '' : '';
    if (link && username !== '') {
      const cleanUsername = username.replace(/^@+/, '');
      link.href = `https://www.instagram.com/${encodeURIComponent(cleanUsername)}/`;
      link.removeAttribute('aria-disabled');
      link.classList.remove('is-disabled');
    } else if (link) {
      link.href = '#';
      link.setAttribute('aria-disabled', 'true');
      link.classList.add('is-disabled');
    }
    if (connectLink && connectUrl !== '') {
      connectLink.href = connectUrl;
      connectLink.removeAttribute('aria-disabled');
      connectLink.classList.remove('is-disabled');
    } else if (connectLink) {
      connectLink.href = '#';
      connectLink.setAttribute('aria-disabled', 'true');
      connectLink.classList.add('is-disabled');
    }
  };

  document.querySelectorAll('.js-post-account-select').forEach((accountSelect) => {
    syncPostInstagramLink(accountSelect);
    accountSelect.addEventListener('change', () => syncPostInstagramLink(accountSelect));
  });

  const syncPostTypeFields = (typeSelect) => {
    const form = typeSelect.closest('form');
    if (!form) {
      return;
    }
    const isNoPost = typeSelect.value === 'no_post';
    form.querySelectorAll('.js-post-detail-field').forEach((field) => {
      field.hidden = isNoPost;
      field.querySelectorAll('input, textarea, select').forEach((input) => {
        input.disabled = isNoPost;
      });
    });
    form.querySelectorAll('.js-no-post-note').forEach((field) => {
      field.hidden = !isNoPost;
      field.querySelectorAll('input, textarea, select').forEach((input) => {
        input.disabled = !isNoPost;
      });
    });
  };

  document.querySelectorAll('.js-post-type-select').forEach((typeSelect) => {
    syncPostTypeFields(typeSelect);
    typeSelect.addEventListener('change', () => syncPostTypeFields(typeSelect));
  });

  document.addEventListener('click', (event) => {
    const modalOpenButton = event.target.closest('[data-modal-target]');
    if (modalOpenButton) {
      const modal = document.getElementById(modalOpenButton.dataset.modalTarget || '');
      if (modal) {
        modal.hidden = false;
        document.body.classList.add('modal-open');
        const firstField = modal.querySelector('select, input, textarea, button');
        if (firstField) {
          window.setTimeout(() => firstField.focus(), 30);
        }
      }
      return;
    }

    const modalCloseButton = event.target.closest('[data-modal-close]');
    if (modalCloseButton) {
      const modal = modalCloseButton.closest('.schedule-modal');
      if (modal) {
        modal.hidden = true;
        if (!document.querySelector('.schedule-modal:not([hidden])')) {
          document.body.classList.remove('modal-open');
        }
      }
      return;
    }

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

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }
    document.querySelectorAll('.schedule-modal:not([hidden])').forEach((modal) => {
      modal.hidden = true;
    });
    document.body.classList.remove('modal-open');
  });
})();
