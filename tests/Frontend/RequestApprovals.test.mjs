import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/Pages/Requests/Approvals.vue', import.meta.url), 'utf8');

test('approval list and dialog expose complete expense purchase details', () => {
    assert.match(source, />采购信息</);
    assert.match(source, /row\.kind === 'expense_request'/);
    assert.match(source, /selected\.kind === 'expense_request'/);
    assert.doesNotMatch(source, /selected\.kind === 'expense_request' && selected\.category === 'software'/);
    assert.match(source, /software_payment_method/);
    assert.match(source, /software_account/);
    assert.match(source, /software_password_set/);
    assert.match(source, /renewalModeLabel/);
    assert.match(source, /billingCycleLabel/);
    assert.match(source, /next_renewal_on/);
});

test('missing renewal mode is not mislabeled as manual renewal', () => {
    assert.match(source, /mode === 'manual' \? '手动续费' : '未填写'/);
});

test('expense requests show the actual expense category', () => {
    assert.match(source, /const categoryLabels: Record<string, string>/);
    assert.match(source, /software: '软件采购'/);
    assert.match(source, /office: '办公采购'/);
    assert.match(source, /申请类型 \/ 费用类型/);
    assert.match(source, /categoryLabel\(row\.category\)/);
    assert.match(source, /categoryLabel\(selected\.category\)/);
});
