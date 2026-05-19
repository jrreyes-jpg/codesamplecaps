document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.project-card').forEach((card) => {
        card.querySelectorAll('.project-tab-button').forEach((button) => {
            button.addEventListener('click', () => {
                const projectId = card.dataset.projectId;
                const selectedPanel = card.querySelector(`#${button.dataset.tab}-${projectId}`);

                card.querySelectorAll('.project-tab-button').forEach((item) => {
                    item.classList.remove('active');
                });

                card.querySelectorAll('.project-panel').forEach((panel) => {
                    panel.classList.remove('active');
                });

                button.classList.add('active');
                selectedPanel?.classList.add('active');
            });
        });
    });
});
