var pageButtons = document.getElementsByClassName('page-button');

for (var i = 0; i < pageButtons.length; i++) {
    pageButtons[i].addEventListener('click', function (event) {
        var page = event.target.getAttribute('data-value')
        if (!event.target.matches('.page-button')) {
            page = event.target.parentElement.getAttribute('data-value');
        }
        event.preventDefault();

        document.getElementById('filter_form_page').value = page;
        document.getElementById('entity-history-form').submit();
    }, false);
}