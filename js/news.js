registerAction("editNewsInline", (el) => {
    const newsId = el.dataset.id;
    const oldTitle = el.dataset.title;
    const oldContent = el.dataset.content;

    const newsBox = el.closest('.box-container');
    const contentDiv = newsBox.querySelector('.news-content');
    const headerTitle = newsBox.querySelector('.news-header-title');

    if (newsBox.querySelector('form')) return;

    const formHtml = `
        <form method="POST" id="edit-news-form-${newsId}" style="width: 100%; text-align: left; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 5px;">
            <input type="hidden" name="news_id" value="${newsId}">
            <input type="hidden" name="edit_news" value="1">
            
            <label style="font-size: 14px; color: var(--link-color);">Titel:</label><br>
            <input type="text" name="title" id="edit-title-${newsId}" value="${oldTitle}" 
                   maxlength="50" style="width: 100%; margin-bottom: 15px;" required>
            
            <label style="font-size: 14px; color: var(--link-color);">Inhalt:</label><br>
            <textarea name="content" id="edit-content-${newsId}" rows="6" maxlength="500" 
                      style="width: 100%; margin-bottom: 15px;" required>${oldContent}</textarea>
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <input type="submit" value="Änderungen speichern">
                <input type="button" value="Abbrechen" data-on-click="cancelNewsEdit">
            </div>
        </form>
    `;

    headerTitle.innerText = "Beitrag bearbeiten";
    contentDiv.innerHTML = formHtml;

    const tools = newsBox.querySelector('.news-admin-tools');
    if (tools) tools.style.display = 'none';

    const inputTitle = document.getElementById(`edit-title-${newsId}`);
    inputTitle.focus();

    const handleKeyDown = (event) => {
        if (event.key === "Escape") {
            document.removeEventListener("keydown", handleKeyDown);
            cancelEdit();
        }
    };
    document.addEventListener("keydown", handleKeyDown);
});
registerAction("cancelNewsEdit", () => {
    cancelEdit();
});

function cancelEdit() {
    window.location.reload();
}