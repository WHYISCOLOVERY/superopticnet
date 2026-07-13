<?php
declare(strict_types=1);

// Backward-compatible route for Anbar/Inventory. The native inventory workflow
// lives inside Operations Center so the system remains a single pane of glass.
header('Location: operations-center.php?section=inventory', true, 302);
exit;
