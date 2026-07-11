(function () {
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
})();
