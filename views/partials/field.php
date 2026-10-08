<?php
/**
 * Satu field formulir. Parameter ($f):
 *   name, label, type (text|select|textarea|month|date|checkbox|number), value, min/max (khusus date), options (select: nilai => label),
 *   error, hint, placeholder, maxlength, required, disabled, autocomplete, inputmode, suffix
 */
$type  = $f['type'] ?? 'text';
$name  = (string) $f['name'];
$id    = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
$value = $f['value'] ?? '';
$error = $f['error'] ?? null;
$attrs = ''
    . (!empty($f['required']) ? ' required' : '')
    . (!empty($f['disabled']) ? ' disabled' : '')
    . (!empty($f['maxlength']) ? ' maxlength="' . (int) $f['maxlength'] . '"' : '')
    . (!empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '')
    . (!empty($f['autocomplete']) ? ' autocomplete="' . e($f['autocomplete']) . '"' : ' autocomplete="off"')
    . (!empty($f['inputmode']) ? ' inputmode="' . e($f['inputmode']) . '"' : '')
    . ($error ? ' aria-invalid="true" aria-describedby="' . e($id) . '_err"' : '');
?>
<?php if ($type === 'checkbox'): ?>
    <div class="field field--check<?= $error ? ' field--error' : '' ?>">
        <label class="check">
            <input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= !empty($value) ? ' checked' : '' ?><?= !empty($f['disabled']) ? ' disabled' : '' ?>>
            <span><?= e($f['label']) ?></span>
        </label>
        <?php if (!empty($f['hint'])): ?><small class="field__hint"><?= e($f['hint']) ?></small><?php endif; ?>
        <?php if ($error): ?><small class="field__error" id="<?= e($id) ?>_err"><?= e($error) ?></small><?php endif; ?>
    </div>
<?php else: ?>
    <div class="field<?= $error ? ' field--error' : '' ?>">
        <label for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '' ?></label>
        <?php if ($type === 'select'): ?>
            <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="input"<?= $attrs ?>>
                <?php foreach (($f['options'] ?? []) as $optValue => $optLabel): ?>
                    <option value="<?= e($optValue) ?>"<?= (string) $optValue === (string) $value ? ' selected' : '' ?>><?= e($optLabel) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($type === 'textarea'): ?>
            <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" class="input" rows="3"<?= $attrs ?>><?= e($value) ?></textarea>
        <?php else: ?>
            <?php if (!empty($f['suffix'])): ?><div class="input-group"><?php endif; ?>
            <input id="<?= e($id) ?>" name="<?= e($name) ?>" class="input<?= !empty($f['inputmode']) && $f['inputmode'] === 'numeric' ? ' input--num' : '' ?>"
                   type="<?= e(in_array($type, ['month', 'date'], true) ? $type : 'text') ?>" value="<?= e($value) ?>"<?= $attrs ?><?= $type === 'date' && !empty($f['min']) ? ' min="' . e($f['min']) . '"' : '' ?><?= $type === 'date' && !empty($f['max']) ? ' max="' . e($f['max']) . '"' : '' ?>>
            <?php if (!empty($f['suffix'])): ?><span class="input-group__suffix"><?= e($f['suffix']) ?></span></div><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($f['hint'])): ?><small class="field__hint"><?= e($f['hint']) ?></small><?php endif; ?>
        <?php if ($error): ?><small class="field__error" id="<?= e($id) ?>_err"><?= e($error) ?></small><?php endif; ?>
    </div>
<?php endif; ?>
