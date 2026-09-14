(function () {
  'use strict';
  var dragged = null;
  document.querySelectorAll('.crm-deal').forEach(function (card) {
    card.addEventListener('dragstart', function () { dragged = card; card.classList.add('is-dragging'); });
    card.addEventListener('dragend', function () { card.classList.remove('is-dragging'); dragged = null; });
  });
  document.querySelectorAll('.crm-column').forEach(function (column) {
    column.addEventListener('dragover', function (event) { event.preventDefault(); column.classList.add('is-over'); });
    column.addEventListener('dragleave', function () { column.classList.remove('is-over'); });
    column.addEventListener('drop', function () {
      column.classList.remove('is-over'); if (!dragged) return;
      var form = new FormData(); form.append('action', 'move_opportunity'); form.append('id', dragged.dataset.id); form.append('stage', column.dataset.stage);
      var token = document.querySelector('[name="_token"]'); if (token) form.append('_token', token.value);
      fetch('crm.php?view=pipeline', {method:'POST', body:form, headers:{Accept:'application/json'}}).then(function (r) { if (!r.ok) throw new Error(); return r.json(); }).then(function () { column.querySelector('.crm-dropzone').appendChild(dragged); }).catch(function () { window.alert('Não foi possível atualizar o pipeline.'); });
    });
  });
}());
