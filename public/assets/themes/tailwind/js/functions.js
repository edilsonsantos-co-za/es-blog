(function () {
	var e = document.querySelector(".menu-open");
	e &&
		e.addEventListener("click", function () {
			document.body.classList.contains("is-menu-open") ? document.body.classList.remove("is-menu-open") : document.body.classList.add("is-menu-open");
		});

	var c = document.querySelector(".menu-close");
	c &&
		c.addEventListener("click", function () {
			document.body.classList.contains("is-menu-open") ? document.body.classList.remove("is-menu-open") : document.body.classList.add("is-menu-open");
		});

	var s = document.querySelector(".search-open");
	s &&
		s.addEventListener("click", function () {
			document.body.classList.contains("is-search-open") ? document.body.classList.remove("is-search-open") : document.body.classList.add("is-search-open");
		});

	var sc = document.querySelector(".search-close");
	sc &&
		sc.addEventListener("click", function () {
			document.body.classList.contains("is-search-open") ? document.body.classList.remove("is-search-open") : document.body.classList.add("is-search-open");
		});

	// Accordion-style dropdown menu handling
	var dropdownToggles = document.querySelectorAll('[data-toggle="dropdown"]');
	dropdownToggles.forEach(function(toggle) {
		toggle.addEventListener("click", function (event) {
			event.preventDefault();

			var parentLi = toggle.closest('li');
			var dropdownMenu = parentLi.querySelector('.dropdown-menu');

			if (!dropdownMenu) return;

			var isOpen = parentLi.classList.contains('open');

			// Close all other dropdowns at the same level
			var siblings = parentLi.parentNode.querySelectorAll(':scope > li.dropdown.open');
			siblings.forEach(function(sibling) {
				if (sibling !== parentLi) {
					sibling.classList.remove('open');
					var siblingMenu = sibling.querySelector('.dropdown-menu');
					if (siblingMenu) {
						siblingMenu.style.display = 'none';
					}
				}
			});

			// Toggle current dropdown
			if (isOpen) {
				parentLi.classList.remove('open');
				dropdownMenu.style.display = 'none';
			} else {
				parentLi.classList.add('open');
				dropdownMenu.style.display = 'block';
			}
		});
	});

})();