<?php if (!empty($gtHasAuthenticatedShell)): ?>
        </main>
    </div>
</div>
<?php else: ?>
    </main>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js?v=<?= h((string) (@filemtime(dirname(__DIR__) . '/assets/app.js') ?: '1')) ?>"></script>
<script src="assets/searchable-select.js?v=<?= h((string) (@filemtime(dirname(__DIR__) . '/assets/searchable-select.js') ?: '1')) ?>"></script>
<script src="assets/mapper-layout.js"></script>
</body>
</html>
