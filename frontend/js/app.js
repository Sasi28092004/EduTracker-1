document.addEventListener(
    "DOMContentLoaded",
    function () {


        const forms =
            document.querySelectorAll(
                "form"
            );


        forms.forEach(
            function (form) {


                form.addEventListener(
                    "submit",
                    function (event) {


                        const password =
                            form.querySelector(
                                'input[name="password"]'
                            );


                        const confirmPassword =
                            form.querySelector(
                                'input[name="confirm_password"]'
                            );


                        if (
                            password &&
                            password.value.length > 0 &&
                            password.value.length < 8
                        ) {

                            event.preventDefault();

                            alert(
                                "Password must contain at least 8 characters."
                            );

                            password.focus();

                            return;

                        }


                        if (
                            password &&
                            confirmPassword &&
                            password.value !==
                            confirmPassword.value
                        ) {

                            event.preventDefault();

                            alert(
                                "Passwords do not match."
                            );

                            confirmPassword.focus();

                            return;

                        }

                    }
                );

            }
        );

    }
);