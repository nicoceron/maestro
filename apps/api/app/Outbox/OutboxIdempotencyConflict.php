<?php

namespace App\Outbox;

use RuntimeException;

final class OutboxIdempotencyConflict extends RuntimeException {}
