// Pekeng cards lang ito. Walang babaguhin sa database.
window.fetch = function () { return new Promise(function () {}); };
window.setInterval = function () { return 0; };

const makeInspectionTestCard = function (id, status, unread) {
    const card = document.createElement('article');
    card.className = 'inspection-card ' + (unread ? 'is-unviewed' : 'is-viewed');
    card.dataset.inspectionCardId = String(id);
    card.innerHTML = '<div class="inspection-card__head"><h2>Sample inspection</h2>'
        + '<span class="inspection-status" data-status="' + status + '">' + status + '</span></div>'
        + '<button type="button" data-inspection-modal-open="inspectionModal' + id + '">View Details</button>'
        + '<div class="inspection-modal" id="inspectionModal' + id + '" hidden>'
        + '<div class="inspection-modal__panel"><button type="button" data-inspection-modal-close>Close</button></div></div>';
    return card;
};

document.addEventListener('DOMContentLoaded', async function () {
    const results = [];
    const check = function (condition, label) {
        results.push((condition ? 'PASS ' : 'FAIL ') + label);
        if (!condition) throw new Error(label);
    };
    const settle = function () {
        return new Promise(function (resolve) { window.setTimeout(resolve, 60); });
    };
    const select = function (filter) {
        document.querySelector('[data-inspection-filter="' + filter + '"]').click();
    };
    const count = function (filter) {
        return Number(document.querySelector('[data-inspection-filter="' + filter + '"] [data-inspection-filter-count]').textContent);
    };
    const panel = document.querySelector('.inspection-panel');
    const empty = panel.querySelector('[data-inspection-filter-empty]');
    const output = document.getElementById('testResult');
    output.dataset.viewportWidth = String(window.innerWidth);
    window.localStorage.removeItem('engineer.siteInspection.activeModal');

    try {
        await settle();
        check(!empty.hidden && count('all') === 0, 'empty list');
        const assigned = makeInspectionTestCard(1, 'Assigned', true);
        const ongoing = makeInspectionTestCard(2, 'Ongoing', false);
        const submitted = makeInspectionTestCard(3, 'Costing Submitted', false);
        const onHold = makeInspectionTestCard(4, 'On Hold', false);
        panel.append(assigned, ongoing, submitted, onHold);
        await settle();
        check(count('all') === 4 && count('new') === 1 && count('ongoing') === 1 && count('submitted') === 1, 'live card counts');
        check(empty.hidden && !assigned.hidden && !ongoing.hidden && !submitted.hidden && !onHold.hidden, 'All includes On Hold');

        select('ongoing');
        check(!ongoing.hidden && assigned.hidden && submitted.hidden && onHold.hidden, 'Ongoing filter');
        check(getComputedStyle(assigned).display === 'none', 'hidden cards stay hidden in CSS');
        check(getComputedStyle(ongoing.querySelector('.inspection-status')).color === 'rgb(29, 78, 216)', 'Ongoing is blue');
        check(getComputedStyle(onHold.querySelector('.inspection-status')).color === 'rgb(154, 52, 18)', 'On Hold stays orange');

        select('submitted');
        check(!submitted.hidden && ongoing.hidden, 'Submitted matches Costing Submitted');
        select('new');
        assigned.querySelector('[data-inspection-modal-open]').click();
        assigned.classList.remove('is-unviewed');
        assigned.classList.add('is-viewed');
        await settle();
        check(count('new') === 0 && !assigned.hidden && !assigned.querySelector('.inspection-modal').hidden, 'read card keeps open modal visible');
        assigned.querySelector('[data-inspection-modal-close]').click();
        await settle();
        check(assigned.hidden && !empty.hidden && empty.textContent === 'No new site inspections.', 'closing read card refreshes New filter');

        const inserted = makeInspectionTestCard(5, 'Assigned', true);
        panel.appendChild(inserted);
        await settle();
        check(!inserted.hidden && empty.hidden && count('new') === 1, 'new assignment joins active filter');
        select('ongoing');
        const status = ongoing.querySelector('.inspection-status');
        status.dataset.status = 'Costing Submitted';
        status.textContent = 'Costing Submitted';
        await settle();
        check(ongoing.hidden && !empty.hidden && count('ongoing') === 0 && count('submitted') === 2, 'workflow change refreshes filter and counts');

        select('submitted');
        const replacement = makeInspectionTestCard(3, 'Assigned', false);
        submitted.replaceWith(replacement);
        await settle();
        check(replacement.hidden && count('submitted') === 1 && !ongoing.hidden, 'server card replacement respects active filter');
        check(document.documentElement.scrollWidth <= window.innerWidth, 'no horizontal page overflow');
        check(Array.from(document.querySelectorAll('.inspection-filter')).every(function (button) {
            const bounds = button.getBoundingClientRect();
            return bounds.width >= 44 && bounds.height >= 44 && bounds.left >= 0 && bounds.right <= window.innerWidth;
        }), 'filter buttons fit viewport with tap space');

        panel.querySelectorAll('[data-inspection-card-id]').forEach(function (card) { card.remove(); });
        await settle();
        check(count('all') === 0 && !empty.hidden && empty.textContent === 'No site inspections yet.', 'last card removal shows empty list');
        document.title = 'PASS';
        output.textContent = results.join('\n');
        output.dataset.result = 'PASS';
    } catch (error) {
        document.title = 'FAIL';
        output.textContent = results.join('\n') + '\n' + error.message;
        output.dataset.result = 'FAIL';
    }
});
