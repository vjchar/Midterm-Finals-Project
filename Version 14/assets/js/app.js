"use strict";

document.documentElement.classList.add("js-enabled");

document.addEventListener("DOMContentLoaded", function () {
    const reducedMotionPreference = window.matchMedia(
        "(prefers-reduced-motion: reduce)",
    );

    function setNavigationState(control, isExpanded) {
        control.checked = isExpanded;
        control.setAttribute("aria-expanded", String(isExpanded));

        const controlledMenuId = control.getAttribute("aria-controls");
        const controlledMenu = controlledMenuId
            ? document.getElementById(controlledMenuId)
            : null;

        if (controlledMenu) {
            controlledMenu.setAttribute("aria-hidden", String(!isExpanded));
        }
    }

    function initializeNavigation(controlId, desktopBreakpoint) {
        const navigationControl = document.getElementById(controlId);

        if (!navigationControl) {
            return;
        }

        function isDesktopLayout() {
            return window.matchMedia(
                "(min-width: " + desktopBreakpoint + "px)",
            ).matches;
        }

        function synchronizeNavigationState() {
            if (isDesktopLayout()) {
                navigationControl.checked = false;
                navigationControl.setAttribute("aria-expanded", "false");

                const menuId = navigationControl.getAttribute("aria-controls");
                const menu = menuId ? document.getElementById(menuId) : null;

                if (menu) {
                    menu.removeAttribute("aria-hidden");
                }

                return;
            }

            setNavigationState(navigationControl, navigationControl.checked);
        }

        navigationControl.addEventListener("change", function () {
            setNavigationState(navigationControl, navigationControl.checked);
        });

        const menuId = navigationControl.getAttribute("aria-controls");
        const navigationMenu = menuId ? document.getElementById(menuId) : null;

        if (navigationMenu) {
            navigationMenu.addEventListener("click", function (event) {
                if (
                    !isDesktopLayout() &&
                    event.target.closest("a, button")
                ) {
                    setNavigationState(navigationControl, false);
                }
            });
        }

        window.addEventListener("resize", synchronizeNavigationState);
        synchronizeNavigationState();

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && navigationControl.checked) {
                setNavigationState(navigationControl, false);
                navigationControl.focus();
            }
        });
    }

    initializeNavigation("siteMenuControl", 992);
    initializeNavigation("adminNavigationControl", 992);

    const siteHeader = document.getElementById("siteHeader");

    if (siteHeader) {
        function updateHeaderSize() {
            siteHeader.classList.toggle("is-compact", window.scrollY > 24);
        }

        window.addEventListener("scroll", updateHeaderSize, { passive: true });
        updateHeaderSize();
    }

    document.querySelectorAll("form").forEach(function (formElement) {
        formElement.addEventListener("submit", function (event) {
            const submitButton = event.submitter;
            const confirmationMessage = formElement.dataset.confirm;

            if (
                confirmationMessage &&
                !window.confirm(confirmationMessage)
            ) {
                event.preventDefault();
                return;
            }

            const skipsValidation = Boolean(
                submitButton && submitButton.formNoValidate,
            );

            if (!skipsValidation && !formElement.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                formElement.classList.add("was-validated");

                const firstInvalidControl = formElement.querySelector(
                    ":invalid",
                );

                if (firstInvalidControl) {
                    firstInvalidControl.focus();
                }

                return;
            }

            if (!submitButton || event.defaultPrevented) {
                return;
            }

            window.setTimeout(function () {
                if (event.defaultPrevented) {
                    return;
                }

                submitButton.classList.add("is-loading");
                submitButton.setAttribute("aria-busy", "true");

                if (!submitButton.dataset.originalLabel) {
                    submitButton.dataset.originalLabel =
                        submitButton.innerHTML;
                }
            }, 0);
        });
    });

    document
        .querySelectorAll('input[type="password"]')
        .forEach(function (passwordInput) {
            if (passwordInput.dataset.visibilityReady === "true") {
                return;
            }

            passwordInput.dataset.visibilityReady = "true";

            const passwordWrapper = document.createElement("div");
            passwordWrapper.className = "password-field";
            passwordInput.parentNode.insertBefore(passwordWrapper, passwordInput);
            passwordWrapper.appendChild(passwordInput);

            const visibilityButton = document.createElement("button");
            visibilityButton.className = "password-toggle";
            visibilityButton.type = "button";
            visibilityButton.setAttribute("aria-label", "Show password");
            visibilityButton.setAttribute("aria-pressed", "false");
            visibilityButton.innerHTML =
                '<i class="bi bi-eye" aria-hidden="true"></i>';

            visibilityButton.addEventListener("click", function () {
                const passwordIsVisible = passwordInput.type === "text";
                passwordInput.type = passwordIsVisible ? "password" : "text";
                visibilityButton.setAttribute(
                    "aria-label",
                    passwordIsVisible ? "Show password" : "Hide password",
                );
                visibilityButton.setAttribute(
                    "aria-pressed",
                    String(!passwordIsVisible),
                );
                visibilityButton.innerHTML = passwordIsVisible
                    ? '<i class="bi bi-eye" aria-hidden="true"></i>'
                    : '<i class="bi bi-eye-slash" aria-hidden="true"></i>';
                passwordInput.focus();
            });

            passwordWrapper.appendChild(visibilityButton);
        });

    document.querySelectorAll("textarea[maxlength]").forEach(function (field) {
        const characterCounter = document.createElement("small");
        characterCounter.className = "character-counter";
        characterCounter.setAttribute("aria-live", "polite");

        function updateCharacterCount() {
            characterCounter.textContent =
                field.value.length + " / " + field.maxLength + " characters";
        }

        field.insertAdjacentElement("afterend", characterCounter);
        field.addEventListener("input", updateCharacterCount);
        updateCharacterCount();
    });

    document.querySelectorAll('input[type="file"]').forEach(function (field) {
        const selectedFileFeedback = document.createElement("small");
        selectedFileFeedback.className = "selected-file-feedback";
        selectedFileFeedback.setAttribute("aria-live", "polite");
        selectedFileFeedback.textContent = "No file selected.";

        field.insertAdjacentElement("afterend", selectedFileFeedback);
        field.addEventListener("change", function () {
            const selectedFiles = Array.from(field.files || []);
            selectedFileFeedback.textContent = selectedFiles.length
                ? selectedFiles.map(function (file) {
                      return file.name;
                  }).join(", ")
                : "No file selected.";
        });
    });

    document.querySelectorAll("form").forEach(function (formElement) {
        const pickupDateInput = formElement.querySelector(
            'input[name="pickup"][type="date"]',
        );
        const returnDateInput = formElement.querySelector(
            'input[name="return"][type="date"]',
        );

        if (!pickupDateInput || !returnDateInput) {
            return;
        }

        function synchronizeDateRange() {
            if (pickupDateInput.value) {
                returnDateInput.min = pickupDateInput.value;
            }

            if (
                returnDateInput.value &&
                pickupDateInput.value &&
                returnDateInput.value < pickupDateInput.value
            ) {
                returnDateInput.value = pickupDateInput.value;
            }
        }

        pickupDateInput.addEventListener("change", synchronizeDateRange);
        synchronizeDateRange();
    });

    const fallbackVehicleImage =
        "assets/images/placeholders/vehicle-placeholder.svg";

    document
        .querySelectorAll(
            ".vehicle-image-wrap img, " +
                ".booking-summary-card > img, " +
                ".compare-table img, " +
                ".recent-vehicle-card img",
        )
        .forEach(function (imageElement) {
        imageElement.addEventListener("error", function () {
            if (imageElement.dataset.fallbackApplied === "true") {
                return;
            }

            imageElement.dataset.fallbackApplied = "true";
            imageElement.src = fallbackVehicleImage;
            imageElement.classList.add("is-placeholder");
        });
    });

    document.querySelectorAll(".faq-accordion").forEach(function (accordion) {
        accordion.querySelectorAll("details").forEach(function (faqItem) {
            faqItem.addEventListener("toggle", function () {
                if (!faqItem.open) {
                    return;
                }

                accordion.querySelectorAll("details[open]").forEach(
                    function (openItem) {
                        if (openItem !== faqItem) {
                            openItem.open = false;
                        }
                    },
                );

                if (!reducedMotionPreference.matches) {
                    faqItem.scrollIntoView({
                        behavior: "smooth",
                        block: "nearest",
                    });
                }
            });
        });
    });

    document.querySelectorAll(".flash-stack .alert").forEach(function (alert) {
        alert.classList.add("alert-dismissible", "fade", "show");

        if (alert.querySelector(".btn-close")) {
            return;
        }

        const dismissButton = document.createElement("button");
        dismissButton.type = "button";
        dismissButton.className = "btn-close";
        dismissButton.setAttribute("data-bs-dismiss", "alert");
        dismissButton.setAttribute("aria-label", "Close message");
        alert.appendChild(dismissButton);
    });
});
