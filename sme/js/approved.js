(() => {
  'use strict';
  function initApprovedPage() {
    const page = document.getElementById('approvedPage');
    if (!page || page.dataset.initialized === '1') return;
    page.dataset.initialized = '1';
    const search = page.querySelector('#apSearch');
    const platform = page.querySelector('#apPlatform');
    const assignment = page.querySelector('#apAssignment');
    const cards = [...page.querySelectorAll('.ap-card')];
    const empty = page.querySelector('#apEmpty');
    const results = page.querySelector('#apResults');
    const toast = page.querySelector('#apToast');
    function showMessage(message, error = false) {
      if (!toast) {
        window.alert(message);
        return;
      }
      toast.textContent = message;
      toast.classList.toggle('is-error', error);
      toast.classList.add('is-visible');
      window.clearTimeout(showMessage.timer);
      showMessage.timer = window.setTimeout(() => {
        toast.classList.remove('is-visible');
      }, 3000);
    }
    function filterCards() {
      const term = (search?.value || '').trim().toLowerCase();
      const selectedPlatform = platform?.value || '';
      const selectedAssignment = assignment?.value || '';
      let visible = 0;
      cards.forEach(card => {
        const matchesSearch =
          (card.dataset.search || '').includes(term);
        const matchesPlatform =
          !selectedPlatform ||
          card.dataset.platform === selectedPlatform;
        const matchesAssignment =
          !selectedAssignment ||
          card.dataset.assigned === selectedAssignment;
        const show =
          matchesSearch &&
          matchesPlatform &&
          matchesAssignment;
        card.hidden = !show;
        if (show) visible++;
      });
      if (results) {
        results.textContent =
          `${visible} ${visible === 1 ? 'item' : 'items'}`;
      }
      if (empty) {
        empty.hidden = visible !== 0;
        const heading = empty.querySelector('h3');
        const message = empty.querySelector('p');
        if (heading) {
          heading.textContent = cards.length
            ? 'No matching creatives'
            : 'No approved creatives found';
        }
        if (message) {
          message.textContent = cards.length
            ? 'Try changing your search or filters.'
            : 'Approved creatives will appear here once they are available.';
        }
      }
    }
    search?.addEventListener('input', filterCards);
    platform?.addEventListener('change', filterCards);
    assignment?.addEventListener('change', filterCards);
    page.addEventListener('click', async event => {
      const button = event.target.closest('.ap-assign-btn');
      if (!button || !page.contains(button)) return;
      const creativeId = button.dataset.creativeId;
      const select = page.querySelector(
        `.ap-smi-select[data-creative-id="${creativeId}"]`
      );
      const smiId = select?.value;
      if (!smiId) {
        showMessage('Please select an SMI employee.', true);
        select?.focus();
        return;
      }
      const originalText = button.textContent;
      button.disabled = true;
      button.textContent = 'Saving...';
      try {
        const body = new URLSearchParams({
          approved_api: 'assign',
          creative_id: creativeId,
          smi_user_id: smiId
        });
        const response = await fetch(
          '/usp/sme/approved.php?approved_api=assign',
          {
            method: 'POST',
            headers: {
              'Content-Type':
                'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString(),
            credentials: 'same-origin'
          }
        );
        const text = await response.text();
        let data;
        try {
          data = JSON.parse(text);
        } catch {
          console.error('PHP response:', text);
          throw new Error(
            'PHP response error. Check the approved.php file.'
          );
        }
        if (!response.ok || !data.success) {
          throw new Error(
            data.message || 'Assignment failed.'
          );
        }
        const selectedName =
          select.options[select.selectedIndex].textContent.trim();
        const card = button.closest('.ap-card');
        const label = card?.querySelector('.ap-assigned-label');
        if (label) {
          label.textContent =
            `Currently assigned to: ${selectedName}`;
        }
        if (card) {
          card.dataset.assigned = 'assigned';
        }
        showMessage('Employee assigned successfully.');
        filterCards();
      } catch (error) {
        console.error('Assignment error:', error);
        showMessage(error.message, true);
      } finally {
        button.disabled = false;
        button.textContent = originalText;
      }
    });
    filterCards();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApprovedPage);
  } else {
    initApprovedPage();
  }
})();
