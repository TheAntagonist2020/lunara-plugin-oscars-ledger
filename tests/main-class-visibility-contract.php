<?php
/**
 * Every Academy_Awards_Table method that another class calls must be public.
 *
 * Why: the classes under includes/ (page store, blocks, explorer, read API...)
 * hold the main plugin instance and call its methods from outside the class.
 * PHP enforces visibility at call time, so a private method called from
 * AAT_Page_Store is a fatal error on live and nowhere else: the runtime tests
 * stub Academy_Awards_Table with public methods and cannot see it. That is
 * exactly how 2.8.14's warm_paths() shipped calling the private
 * get_table_name() and crashed WP-Cron every minute from 2.8.15 on.
 *
 * Declarations are read with the tokenizer from the real main file; call sites
 * are every "$plugin->", "$aat->", "$aat_instance->" and
 * "Academy_Awards_Table::get_instance()->" method call under includes/.
 * (Templates are left out: they are included from inside the class and may
 * legitimately reach private methods.)
 *
 * Run: php tests/main-class-visibility-contract.php
 */

$root = dirname(__DIR__);
$main = $root . '/academy-awards-table.php';
$failures = array();

// ---- Declarations: method => visibility, for class Academy_Awards_Table only ----
$visibility = array();
$tokens = token_get_all((string) file_get_contents($main));
$in_class = false;
$depth = 0;
$class_depth = null;
$pending_visibility = null;
$count = count($tokens);
for ($i = 0; $i < $count; $i++) {
    $t = $tokens[$i];
    if (is_string($t)) {
        if ($t === '{') {
            $depth++;
        } elseif ($t === '}') {
            $depth--;
            if ($in_class && $class_depth !== null && $depth < $class_depth) {
                $in_class = false;
                $class_depth = null;
            }
        }
        continue;
    }
    list($id, $text) = $t;
    if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
        $depth++;
        continue;
    }
    if (!$in_class && $id === T_CLASS) {
        // Skip "::class" and anonymous classes; look for "class Academy_Awards_Table".
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === 'Academy_Awards_Table') {
                $in_class = true;
                $class_depth = $depth + 1; // the class body opens at the next "{"
            }
            break;
        }
        continue;
    }
    if (!$in_class || $depth !== $class_depth) {
        // Only direct members of the class body (not nested closures/classes).
        if ($in_class && in_array($id, array(T_PUBLIC, T_PRIVATE, T_PROTECTED), true) && $depth === $class_depth) {
            $pending_visibility = strtolower($text);
        }
        continue;
    }
    if (in_array($id, array(T_PUBLIC, T_PRIVATE, T_PROTECTED), true)) {
        $pending_visibility = strtolower($text);
        continue;
    }
    if ($id === T_FUNCTION) {
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            if (is_string($tokens[$j]) && $tokens[$j] === '&') {
                continue;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $visibility[$tokens[$j][1]] = $pending_visibility ?: 'public'; // no keyword = public
            }
            break;
        }
        $pending_visibility = null;
        continue;
    }
    if ($id === T_VARIABLE || $id === T_CONST) {
        $pending_visibility = null; // a property or constant consumed the keyword
    }
}

if (count($visibility) < 50) {
    $failures[] = 'Expected to read the Academy_Awards_Table method table from the main file; got ' . count($visibility) . ' methods.';
}
if (($visibility['get_instance'] ?? '') !== 'public') {
    $failures[] = 'Sanity: get_instance() should be read as public.';
}

// ---- Call sites under includes/ ------------------------------------------------
$pattern = '/(?:\$plugin|\$aat|\$aat_instance|Academy_Awards_Table::get_instance\(\))->([A-Za-z_][A-Za-z0-9_]*)\s*\(/';
$calls = array();
foreach (glob($root . '/includes/*.php') as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $hit) {
            $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $calls[] = array('method' => $hit[0], 'where' => basename($file) . ':' . $line);
        }
    }
}
if (count($calls) < 10) {
    $failures[] = 'Expected to find main-class calls under includes/; found ' . count($calls) . '.';
}

foreach ($calls as $call) {
    $method = $call['method'];
    if (!array_key_exists($method, $visibility)) {
        $failures[] = "{$call['where']} calls Academy_Awards_Table::{$method}(), which the main class does not declare.";
        continue;
    }
    if ($visibility[$method] !== 'public') {
        $failures[] = "{$call['where']} calls Academy_Awards_Table::{$method}() from another class, but it is {$visibility[$method]}. PHP will throw a fatal error at that call.";
    }
}

if ($failures) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

echo 'Main class visibility contract OK: ' . count($calls) . ' cross-class calls into ' . count($visibility) . " declared methods, all public.\n";
