import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/Pages/Requests/CostApplications.vue', import.meta.url), 'utf8');

test('software payment method uses the approved radio options', () => {
    for (const option of [
        '信用卡2671(Meggie)',
        '信用卡9946(李总)',
        '信用卡7133(Meggie)',
        'Abby代付',
        'Meggie代付',
    ]) {
        assert.match(source, new RegExp(option.replace(/[()]/g, '\\$&')));
    }
    assert.match(source, /type="radio"/);
    assert.match(source, /<span>其他<\/span>/);
});

test('custom payment method field is only shown for other', () => {
    assert.match(source, /v-if="paymentMethodChoice === 'other'"/);
    assert.match(source, />\s*填写付费方式\s*</);
    assert.match(source, /form\.software_payment_method = choice === "other"/);
});
