$(window).on('load', function() {
    $('.loader').fadeOut(300);
    new WOW().init();
    $('.player-block').on('click', function() {
        var audio = $(this).closest('.player-block').find('.player')[0];
        var playerBlock = $(this).closest('.player-block');
        if (!audio.paused) {
            audio.pause();
            audio.currentTime = 0; 
            playerBlock.removeClass('active');
        } else {
            $('audio').each(function() {
                this.pause();
                this.currentTime = 0; 
            });
            $('.player-block').removeClass('active');
            audio.play();
            playerBlock.addClass('active');
        }
    });
    $(".player-btn-icon").hover(
        function() {
            $(this).find('img').slideToggle(0);
            $(this).addClass('active');
        },
        function() {
            $(this).find('img').slideToggle(0);
            $(this).removeClass('active');
        }
    );
    $(".header-soc-item").hover(
        function() {
            $(this).find('img').slideToggle(0);
        },
        function() {
            $(this).find('img').slideToggle(0);
        }
    );
    $(".menu-item").hover(
        function() {
            $(this).addClass('active');
        },
        function() {
            $(this).removeClass('active');
        }
    );

    $('.four-slider').slick({
        slidesToShow: 3,
        dots: true,
        responsive: [{
                breakpoint: 800, 
                settings: {
                    slidesToShow: 2,
                    slidesToScroll: 2
                }
            },
            {
                breakpoint: 600, 
                settings: {
                    slidesToShow: 1,
                    slidesToScroll: 1
                }
            }
        ]
    });

    $('.nine-inner-header').click(function() {
        $('.nine-inner').not($(this).closest('.nine-inner')).removeClass('active');
        $(this).closest('.nine-inner').toggleClass('active');
        $('.nine-inner-text').not($(this).closest('.nine-inner').find('.nine-inner-text')).slideUp(100);
        $(this).closest('.nine-inner').find('.nine-inner-text').slideToggle(100);
    });

    $(window).scroll(function() {
        if ($(this).scrollTop() > 0) {
            $('.header').addClass('active');
        } else {
            $('.header').removeClass('active');
        }
    });

    $('.eleven-form-tell').not($(this).find('input')).click(function() {
        $(this).find('input').focus();
    });

    $('[name="phone"]').on('focus', function() {
        if ($(this).val() === '') {
            $(this).val('+');
        }
        var len = $(this).val().length;
        this.setSelectionRange(len, len);
    }).on('blur', function() {
        if ($(this).val() === '+') {
            $(this).val('');
        }
    });

    $('.bars').click(function() {
        $(this).toggleClass('active');
        $('.mob-menu').slideToggle(400);
    });

    $(".scroll-btn").click(function() {
        var scrollTo = $(this).attr('data-scroll');
        $('html, body').animate({
            scrollTop: $(scrollTo).offset().top
        }, 500);
        if ($('.bars').hasClass('active')) {
            $('.bars').click();
        }
    });

    // Записуємо час завантаження сторінки для форми з email
    $('[name="loaded_at"]').val(Date.now());

    $('form').on('submit', function(event) {
        event.preventDefault();

        var $form   = $(this);
        var $submit = $form.find('[type="submit"]');
        var data    = {
            name:    $form.find('[name="name"]').val(),
            message: $form.find('[name="message"]').val()
        };

        // Форма запису — є поле phone
        if ($form.find('[name="phone"]').length) {
            var phone = $form.find('[name="phone"]').val();
            var digitsOnly = phone.replace(/\D/g, '');
            if (!phone.startsWith('+380') || digitsOnly.length !== 12) {
                alert('Будь ласка, введіть коректний номер телефону');
                $submit.val('Відправити').prop('disabled', false);
                return;
            }
            data.phone = phone;
        }

        // Контактна форма — є поле email + honeypot + таймер
        if ($form.find('[name="email"]').length) {
            data.email     = $form.find('[name="email"]').val();
            data.website   = $form.find('[name="website"]').val();
            data.loaded_at = $form.find('[name="loaded_at"]').val();
        }

        $submit.val('Відправляємо...').prop('disabled', true);

        fetch('/send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                $form.find('input:not([type="submit"]), textarea').val('');
                $('.f-modal-container').fadeIn(200);
            } else {
                alert('Помилка: ' + result.message);
            }
        })
        .catch(function() {
            alert('Помилка з\'єднання. Спробуйте пізніше.');
        })
        .finally(function() {
            $submit.val('Відправити').prop('disabled', false);
        });
    });

    $(document).on('click', function(event) {
        if (!$(event.target).closest('.f-modal-content').length && $('.f-modal-container').is(':visible')) {
            $('.f-modal-container').fadeOut(200);
        }
    });

    $('.f-modal-content-close').click(function() {
        $(this).closest('.f-modal-container').fadeOut(200);
    });
});
