$(document).ready(function () {

    $('#datatable').DataTable({
        "dom": 'frtip',
        "responsive": true,
        "bSort": false,
        "pageLength": 70,

        language: {
            searchPlaceholder: "Sütunlar üzrə axtarış",
            info: '_TOTAL_ məllumatdan _START_ - _END_ qədər məllumat göstərilir.',
            infoEmpty: '',
            zeroRecords: 'Məllumat yoxdur',
            sSearch: '',
            lengthMenu: ' _MENU_ ',
            loadingRecords: 'Məllumatlar yüklənir',
            infoFiltered: '(toplam _MAX_ məllumatdan filtirlənənlər)',
            oPaginate: {
                sFirst: "İlk",
                sLast: "Son",
                sNext: "İləri",
                sPrevious: "Geri"
            }
        }
    });



    $('#datatable_error').DataTable({
        "dom": 'rtip',
        "responsive": true,
        "bSort": false,
        "pageLength": 70,
        search: {
            search: 'Deaktiv'
        },
        language: {
            searchPlaceholder: "Sütunlar üzrə axtarış",
            info: '_TOTAL_ məllumatdan _START_ - _END_ qədər məllumat göstərilir.',
            infoEmpty: '',
            zeroRecords: 'Problemli istifadəçi yoxdur',
            sSearch: '',
            lengthMenu: ' _MENU_ ',
            loadingRecords: 'Məllumatlar yüklənir',
            infoFiltered: '(toplam _MAX_ məllumatdan filtirlənənlər)',
            oPaginate: {
                sFirst: "İlk",
                sLast: "Son",
                sNext: "İləri",
                sPrevious: "Geri"
            }
        }
    });

    $('#datatable2').DataTable({
        bLengthChange: false,
        searching: false,
        responsive: true
    });

    // Select2
    $('.dataTables_length select').select2({
        minimumResultsForSearch: Infinity
    });


    dataTable_ppp_user = $('#example_ppp_user').DataTable({
        "dom": 'Blfritp',
        "responsive": true,
        "scrollX": false,
        "bSort": false,
        "searching": false,
        "pageLength": 70,
        "lengthMenu": [[70, 100, 500, 1000, 2000, 5000], [70, 100, 500, 1000, 2000, 5000]],

        "language": {
            info: "_TOTAL_ abonentdın _START_ - _END_ qədər abonent göstərilir.",
            infoEmpty: "Məllumat yoxdur",
            processing: "",
            lengthMenu: " _MENU_ ",
            loadingRecords: "Məllumatlar yüklənir",
            zeroRecords: "Məllumat yoxdur",
            search: "",
            searchPlaceholder: "Sütunlar üzrə axtarış",
            infoFiltered: "(toplam _MAX_ abonentdən filtirlənənlər)",
            oPaginate: {
                sFirst: "İlk",
                sLast: "Son",
                sNext: "İləri",
                sPrevious: "Geri"
            },
            buttons: {
                copyTitle: "Kopyalandı.",
                copySuccess: "%d sətir kopyalandı",
                copy: "Kopyala",
                print: "Çap et<br>",
                colvis: "Sütünlar"
            },
        },
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': 'table/ppp_user.php',
            'data': function (data) {
                var router_open_id = $('#js_router_detail_id').text();
                data.router_open_id = router_open_id;
                var user_status = $('#js_user_status').text();
                data.user_status = user_status;
                var internet_status = $('#internet_status').val();
                data.internet_status = internet_status;
                var internet_paket = $('#internet_paket').val();
                data.internet_paket = internet_paket;
                var personal_search = $('#personal_search').val();
                data.personal_search = personal_search;
                var personal_a_search = $('#personal_a_search').val();
                data.personal_a_search = personal_a_search;
                var personal_ip_search = $('#personal_ip_search').val();
                data.personal_ip_search = personal_ip_search;
                var user_borc = $('#user_borc').val();
                data.user_borc = user_borc;
                var zone_filter = $('#zone_filter').val();
                data.zone_filter = zone_filter;
                var static_ip_filter = $('#static_ip_filter').val();
                data.static_ip_filter = static_ip_filter;
            }
        },

        'columns': [
            { data: 'id' },
            { data: 'ad' },

            { data: 'fin' },
            { data: 'unvan' },
            { data: 'telefon' },

            { data: 'balans' },
            { data: 'paket' },
            { data: 'day' },
            { data: 'borc' },


            { data: 'status' }




        ],







    });








    $('#personal_search').keyup(function () {
        dataTable_ppp_user.draw(false);
    });
    $('#personal_a_search').keyup(function () {
        dataTable_ppp_user.draw(false);
    });
    $('#personal_ip_search').keyup(function () {
        dataTable_ppp_user.draw(false);
    });

    $('#internet_status').change(function () {
        dataTable_ppp_user.draw(false);
    });
    $('#zone_filter').change(function () {
        dataTable_ppp_user.draw(false);
    });

    $('#internet_paket').change(function () {
        dataTable_ppp_user.draw(false);
    });
    $('#user_borc').change(function () {
        dataTable_ppp_user.draw(false);
    });
    $('#static_ip_filter').change(function () {
        dataTable_ppp_user.draw(false);
    });















    dataTable_ppp_user2 = $('#example_ppp_user2').DataTable({
        "dom": 'Blfrtip',
        "responsive": true,
        "scrollX": false,
        "bSort": false,
        "searching": false,
        "pageLength": 70,
        "lengthMenu": [[70, 100, 500, 1000, 5000], [70, 100, 500, 1000, 5000]],

        "language": {
            info: "_TOTAL_ abonentdın _START_ - _END_ qədər abonent göstərilir.",
            infoEmpty: "Məllumat yoxdur",
            processing: "",
            lengthMenu: " _MENU_ ",
            loadingRecords: "Məllumatlar yüklənir",
            zeroRecords: "Məllumat yoxdur",
            search: "",
            searchPlaceholder: "Sütunlar üzrə axtarış",
            infoFiltered: "(toplam _MAX_ abonentdən filtirlənənlər)",
            oPaginate: {
                sFirst: "İlk",
                sLast: "Son",
                sNext: "İləri",
                sPrevious: "Geri"
            },
            buttons: {
                copyTitle: "Kopyalandı.",
                copySuccess: "%d sətir kopyalandı",
                copy: "Kopyala",
                print: "Çap et<br>",
                colvis: "Sütünlar"
            },
        },
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': 'table/ppp_user_all_data.php',
            'data': function (data) {
                var router_open_id = $('#js_router_detail_id').text();
                data.router_open_id = router_open_id;
                var user_status = $('#js_user_status').text();
                data.user_status = user_status;
                var internet_status = $('#internet_status').val();
                data.internet_status = internet_status;
                var internet_paket = $('#internet_paket').val();
                data.internet_paket = internet_paket;
                var personal_search = $('#personal_search').val();
                data.personal_search = personal_search;
                var personal_a_search = $('#personal_a_search').val();
                data.personal_a_search = personal_a_search;
                var user_borc = $('#user_borc').val();
                data.user_borc = user_borc;
            }
        },

        'columns': [
            { data: 'id' },
            { data: 'ad' },
            { data: 'seria' },
            { data: 'fin' },
            { data: 'unvan' },
            { data: 'telefon' },

            { data: 'balans' },
            { data: 'paket' },
            { data: 'day' },
            { data: 'borc' },


            { data: 'status' }




        ],







    });







});















$(document).on("click", ".profil_edit", function () {
    var e = $(this).attr("id"),
        i = $("#profil").val();
    $.confirm({
        title: !1,
        type: "red",
        content: "Paketi dəyişdirməyə əminsiniz  ? <br>Seçiminizi 1 gün ərzidən dəyişə bilmərsiniz !",
        buttons: {
            "Bəlİ": function () {
                $.ajax({
                    type: "post",
                    url: 'api/core/core.php',
                    data: {
                        edit_profil: e,
                        p: i
                    },
                    success: function (e) {
                        2 == e ? $.dialog({
                            title: "Xeta !",
                            closeIcon: !0,
                            icon: "fas fa-check",
                            content: "İpiniz qeydə alındı.",
                            type: "red",
                            typeAnimated: !0
                        }) : 1 == e && ($.dialog({
                            title: "Uğurlu !",
                            closeIcon: !0,
                            icon: "fas fa-check",
                            content: "Uğurla paketiniz dəyişdirildi.",
                            type: "green",
                            typeAnimated: !0
                        }), setInterval(function () {
                            location.reload();
                        }, 1500))
                    }
                })
            },
            Xeyr: function () { }
        }
    })
});



$(document).ready(function () {





    dataTable_ppp_pay = $('#example_pay').DataTable({
        "dom": 'Bfrtip',
        "responsive": true,
        "scrollX": false,
        "bSort": false,
        "searching": false,
        "pageLength": 70,

        "language": {
            info: "_TOTAL_ ödənişdən _START_ - _END_ qədər ödəniş göstərilir.",
            infoEmpty: "Məllumat yoxdur",
            processing: "",
            lengthMenu: " _MENU_ ",
            loadingRecords: "Məllumatlar yüklənir",
            zeroRecords: "Məllumat yoxdur",
            search: "",
            searchPlaceholder: "Sütunlar üzrə axtarış",
            infoFiltered: "(toplam _MAX_ ödənişdən filtirlənənlər)",
            oPaginate: {
                sFirst: "İlk",
                sLast: "Son",
                sNext: "İləri",
                sPrevious: "Geri"
            },
            buttons: {
                copyTitle: "Kopyalandı.",
                copySuccess: "%d sətir kopyalandı",
                copy: "Kopyala",
                print: "Çap et<br>",
                colvis: "Sütünlar"
            },
        },
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': 'table/payment.php',
            'data': function (data) {

                var personal_pay_search = $('#personal_pay_search').val();
                data.personal_pay_search = personal_pay_search;
                var pay_status = $('#pay_status').val();
                data.pay_status = pay_status;
                var pay_zone = $('#pay_zone').val();
                data.pay_zone = pay_zone;
                var personal_pay_start = $('#personal_pay_start').val();
                data.personal_pay_start = personal_pay_start;
                var personal_pay_end = $('#personal_pay_end').val();
                data.personal_pay_end = personal_pay_end;
            }
        },

        'columns': [
            { data: 'id' },
            { data: 'user' },
            { data: 'terminal' },
            { data: 'odenis' },

            { data: 'transaction' },
            { data: 'date' },
            { data: 'action' }



        ],
        createdRow: function (row, data, index) {
            if (data['pay_count'] != '') {
                $('#pay_count').text(data['pay_count']);
            }
            if (data['pay_order_price'] != '') {
                $('#pay_order_price').text(data['pay_order_price'] + " ₼");
            }

        }





    });

    $('#personal_pay_search').keyup(function () {
        dataTable_ppp_pay.draw(false);
    });
    $('#personal_pay_end').change(function () {
        dataTable_ppp_pay.draw(false);
    });
    $('#personal_pay_start').change(function () {
        dataTable_ppp_pay.draw(false);
    });
    $('#pay_status').change(function () {
        dataTable_ppp_pay.draw(false);
    });
    $('#pay_zone').change(function () {
        dataTable_ppp_pay.draw(false);
    });


});












$(document).ready(function () {
    dataTable_boxs = $('#example_ppp_paket').DataTable({
        "dom": 'frtip',

        "responsive": true,

        "scrollX": false,
        "info": true,
        "pageLength": 70,

        "language": {
            info: "_TOTAL_ paketdən _START_ - _END_ qədər paket göstərilir.",
            infoEmpty: "Məllumat yoxdur",
            processing: "",
            lengthMenu: " _MENU_ ",
            loadingRecords: "Məllumatlar yüklənir",
            zeroRecords: "Məllumat yoxdur",
            search: "",
            searchPlaceholder: "Sütunlar üzrə axtarış",
            infoFiltered: "(toplam _MAX_ paketdən filtirlənənlər)",
            oPaginate: {
                sFirst: "İlk",
                sLast: "Son",
                sNext: "İləri",
                sPrevious: "Geri"
            },
            buttons: {
                copyTitle: "Kopyalandı.",
                copySuccess: "%d sətir kopyalandı",
                copy: "Kopyala",
                print: "Çap et<br>",
                colvis: "Sütünlar"
            },
        },
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': 'table/ppp_paket.php',
            'data': function (data) {
                var user_status = $('#js_user_status').text();
                data.user_status = user_status;
                var router_open_id = $('#js_router_detail_id').text();
                data.router_open_id = router_open_id;
                var status_boxs = $('#status_boxs').val();
                data.status_boxs = status_boxs;
                var personal_search = $('#personal_search').val();
                data.personal_search = personal_search;
                var personal_a_search = $('#personal_a_search').val();
                data.personal_a_search = personal_a_search;
            }
        },
        // ----- Bazadan ferqli mullumat almaq
        // Sutunlar idare edilir sutun yurinde gore tabloda ferq edir row.stunadi ile sutun secilir
        'columns': [
            { data: 'id' },
            { data: 'ad' },

            { data: 'rate' },
            { data: 'qiymet' },
            { data: 'status' }




        ]
    });


});













$(document).ready(function () {
    dataTable_boxs = $('#example_hotspot_paket').DataTable({
        "dom": 'frtip',

        "responsive": true,

        "scrollX": false,
        "info": true,
        pageLength: 500000000000000000000000000000000000000000000000000000000,

        "language": {
            info: "_TOTAL_ paketdən _START_ - _END_ qədər paket göstərilir.",
            infoEmpty: "Məllumat yoxdur",
            processing: "",
            lengthMenu: " _MENU_ ",
            loadingRecords: "Məllumatlar yüklənir",
            zeroRecords: "Məllumat yoxdur",
            search: "",
            searchPlaceholder: "Sütunlar üzrə axtarış",
            infoFiltered: "(toplam _MAX_ paketdən filtirlənənlər)",
            oPaginate: {
                sFirst: "İlk",
                sLast: "Son",
                sNext: "İləri",
                sPrevious: "Geri"
            },
            buttons: {
                copyTitle: "Kopyalandı.",
                copySuccess: "%d sətir kopyalandı",
                copy: "Kopyala",
                print: "Çap et<br>",
                colvis: "Sütünlar"
            },
        },
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': 'table/hotspot_paket.php',
            'data': function (data) {
                var user_status = $('#js_user_status').text();
                data.user_status = user_status;
                var router_open_id = $('#js_router_detail_id').text();
                data.router_open_id = router_open_id;
                var status_boxs = $('#status_boxs').val();
                data.status_boxs = status_boxs;
                var personal_search = $('#personal_search').val();
                data.personal_search = personal_search;
            }
        },
        // ----- Bazadan ferqli mullumat almaq
        // Sutunlar idare edilir sutun yurinde gore tabloda ferq edir row.stunadi ile sutun secilir
        'columns': [
            { data: 'id' },
            { data: 'ad' },
            { data: 'local' },
            { data: 'remote' },
            { data: 'rate' },
            { data: 'qiymet' },
            { data: 'status' }




        ]
    });


});









// Router redakte et



$(document).on('click', '.router_edit', function () {
    var router_view_id = $(this).attr('id');
    $('#router_edit_id').val(router_view_id);
    $.ajax({
        type: 'post',
        url: 'api/core/core.php',
        data: { "router_view_id": router_view_id },
        success: function (msg) {
            var writer_router_edit = JSON.parse(msg);

            $('#router_model').val(writer_router_edit.model);
            $('#router_name').val(writer_router_edit.name);
            $('#router_ip').val(writer_router_edit.ip);
            $('#router_port').val(writer_router_edit.port);
            $('#router_view_port option[value=' + writer_router_edit.view_port + ']').attr('selected', 'selected');
            $('#router_login').val(writer_router_edit.login_r);
            $('#router_pass').val(writer_router_edit.pass);
            $('#router_telegram').val(writer_router_edit.telegram);
            $('#router_chat').val(writer_router_edit.chat);

        }
    });
});



// Router redakte 

$(function () {
    $("#edit_router").validate({
        errorClass: "authError",
        rules: {
            router_model_edit: {
                required: true
            },
            router_name_edit: {
                required: true
            },

            router_ip_edit: {
                required: true
            },

            router_port_edit: {
                required: true
            },

            router_login_edit: {
                required: true
            },

            router_pass_edit: {
                required: true
            }


        },
        messages: {

            router_model_edit: {
                required: "Model adı daxil edin",

            },
            router_name_edit: {
                required: "Ad daxil edin",

            },
            router_ip_edit: {
                required: "İp daxil edin",

            },
            router_port_edit: {
                required: "Port daxil edin",

            },
            router_login_edit: {
                required: "İstifadəçi adı daxil edin",

            },
            router_pass_edit: {
                required: "Şifrə daxil edin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#edit_router').serialize(),
                success: function (msg) {
                    if (msg == 1) {

                        location.reload();

                    } else if (msg == 1) {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-exclamation-circle',
                            content: 'Yenidən yoxlayın.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});







// IP tv elave et

$(function () {
    $("#add_iptv").validate({
        errorClass: "authError",
        rules: {
            iptv_seria: {
                required: true
            },
            iptv_start: {
                required: true
            }



        },
        messages: {

            iptv_seria: {
                required: "Avadanlıq seriası daxil edin.",

            },
            iptv_start: {
                required: "Başlama tarixi daxil edin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_iptv').serialize(),
                success: function (msg) {
                    if (msg == 1) {

                        location.reload();

                    } else if (msg == 1) {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-exclamation-circle',
                            content: 'Yenidən yoxlayın.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});




// İP elave et

$(function () {
    $("#add_static_ip").validate({
        errorClass: "authError",
        rules: {
            static_ip_ip: {
                required: true
            },
            static_ip_start: {
                required: true
            }



        },
        messages: {

            static_ip_ip: {
                required: "İP seriası daxil edin.",

            },
            static_ip_start: {
                required: "Başlama tarixi daxil edin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_static_ip').serialize(),
                success: function (msg) {
                    if (msg == 1) {

                        location.reload();

                    } else if (msg == 1) {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-exclamation-circle',
                            content: 'Yenidən yoxlayın.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});


// SİP elave et

$(function () {
    $("#add_sip").validate({
        errorClass: "authError",
        rules: {
            sip_phone: {
                required: true
            },
            sip_pass: {
                required: true
            },
            sip_start: {
                required: true
            }



        },
        messages: {

            sip_phone: {
                required: "Nömrə daxil edin.",

            },
            sip_pass: {
                required: "Şifrə daxil edin.",

            },
            sip_start: {
                required: "Başlama tarixi daxil edin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_sip').serialize(),
                success: function (msg) {
                    if (msg == 1) {

                        location.reload();

                    } else if (msg == 1) {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-exclamation-circle',
                            content: 'Yenidən yoxlayın.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});




// Cabel tv elave et

$(function () {
    $("#add_cabel_tv").validate({
        errorClass: "authError",
        rules: {
            cabel_tv_port: {
                required: true
            },
            cabel_tv_start: {
                required: true
            }



        },
        messages: {

            cabel_tv_port: {
                required: "Port daxil edin.",

            },
            cabel_tv_start: {
                required: "Başlama tarixi daxil edin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_cabel_tv').serialize(),
                success: function (msg) {
                    if (msg == 1) {

                        location.reload();

                    } else if (msg == 1) {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-exclamation-circle',
                            content: 'Yenidən yoxlayın.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});





// Negd odenis

$(function () {
    $("#cash_payment").validate({
        errorClass: "authError",
        rules: {
            user_id: {
                required: true
            },
            amount: {
                required: true
            },

            paymen: {
                required: true
            }

        },
        messages: {

            user_id: {
                required: "Abonent kodunu seçin",

            },
            amount: {
                required: "Qiymət daxil edin",

            },
            paymen: {
                required: "Ödəyici seçin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'https://billing.superoptic.net/payments/pay.php',
                data: $('#cash_payment').serialize(),
                success: function (msg) {
                    var cash = JSON.parse(msg);
                    if (cash.status == 200) {
                        $('#cash_payment').trigger("reset");
                        $.dialog({
                            title: 'Uğurlu !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Ödəniş uğurla ödənildi.',
                            type: 'green',
                            typeAnimated: true,
                        });


                        setTimeout(function () {
                            location = ''
                        }, 1500)



                    }
                }
            });
        }
    });
});











// Router redakte 

$(function () {
    $("#add_router").validate({
        errorClass: "authError",
        rules: {
            router_model_add: {
                required: true
            },
            router_name_add: {
                required: true
            },

            router_ip_add: {
                required: true
            },

            router_port_add: {
                required: true
            },

            router_login_add: {
                required: true
            },

            router_pass_add: {
                required: true
            }


        },
        messages: {

            router_model_add: {
                required: "Model adı daxil edin",

            },
            router_name_add: {
                required: "Ad daxil edin",

            },
            router_ip_add: {
                required: "İp daxil edin",

            },
            router_port_add: {
                required: "Port daxil edin",

            },
            router_login_add: {
                required: "İstifadəçi adı daxil edin",

            },
            router_pass_add: {
                required: "Şifrə daxil edin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_router').serialize(),
                success: function (msg) {
                    if (msg == 1) {

                        location.reload();

                    } else if (msg == 1) {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-exclamation-circle',
                            content: 'Yenidən yoxlayın.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});




// Rouert sil



$(document).on('click', '.delete_router', function () {

    var router_delete_id = $(this).attr('id');

    $.confirm({

        title: false,

        content: 'Silmək istədiyinizə əminsiniz ?',

        buttons: {

            Bəlİ: function () {

                $.ajax({

                    type: 'post',

                    url: 'api/core/core.php',

                    data: {

                        'router_delete_id': router_delete_id

                    },

                    success: function (cavab) {

                        location.reload();

                    }
                });

            },

            Xeyr: function () { }
        }
    });



});



// Router scan



$(document).on('click', '.scan_router', function () {

    var router_scan_ip = $(this).attr('id');




    $.confirm({
        type: 'red',
        typeAnimated: true,
        buttons: {

            Bağla: function () { }
        },
        content: function () {

            var self = this;
            return $.ajax({
                url: 'api/scan_routers.php',
                data: {
                    'router_scan_ip': router_scan_ip
                },
                dataType: 'json',
                type: 'post',
            }).done(function (response) {
                self.setContent('İp: ' + response.ip);
                self.setContentAppend('<br>Port: ' + response.port);
                self.setTitle(response.name);
            }).fail(function () {
                self.setContent('Something went wrong.');
            });

        }
    });






});


// Router open



$(document).on('click', '.open_router', function () {

    var router_open_ip = $(this).attr('id');




    $.ajax({

        type: 'post',

        url: 'api/scan_routers.php',

        data: {

            'router_open_ip': router_open_ip

        },

        success: function (cavab) {
            if (cavab == 1) {
                location.href = 'panel.php';
            } else if (cavab == 2) {
                $.confirm({
                    title: 'Bağlantı yaradılmadı ',
                    content: 'Parametirlər yalnışdır !',
                    type: 'red',
                    typeAnimated: true,
                    buttons: {

                        Bağla: function () { }
                    }
                });
            } else if (cavab == 3) {
                $.confirm({
                    title: 'Bağlantı yaradılmadı ',
                    content: 'İp və ya port məllumatları yalnışdır',
                    type: 'red',
                    typeAnimated: true,
                    buttons: {

                        Bağla: function () { }
                    }
                });
            }

        }
    });



});




// add pppoe 

$(function () {
    $("#add_pppoe_form").validate({
        errorClass: "authError",
        rules: {
            ppp_name: {
                required: true
            },
            ppp_pass: {
                required: true
            },
            ppp_ats_select: {
                required: true
            },
            ppp_service: {
                required: true
            },
            ppp_profile: {
                required: true
            },
            ppp_zone_select: {
                required: true
            },
            ppp_ip_type: {
                required: true
            },
            ppp_fin: {
                required: true,
                minlength: 7,
                maxlength: 7
            },
            ppp_phone: {
                required: true
            }
        },
        messages: {
            ppp_name: {
                required: "Ad daxil edin",
            },
            ppp_pass: {
                required: "Şifrə daxil edin",
            },
            ppp_ats_select: {
                required: "Zona seçin",
            },
            ppp_service: {
                required: "Xidmət seçin",
            },
            ppp_profile: {
                required: "Paket seçin",
            },
            ppp_zone_select: {
                required: "Pool seçin",
            },
            ppp_ip_type: {
                required: "IP tipini seçin",
            },
            ppp_fin: {
                required: "FİN kodu daxil edin!",
                minlength: "Zəhmət olmasa 7 simvol daxil edin.",
                maxlength: "Zəhmət olmasa 7 simvol daxil edin."
            },
            ppp_phone: {
                required: "Telefon nömrəsi daxil edin"
            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_pppoe_form').serialize(),
                success: function (msg) {
                    if (msg == 1) {
                        $('#add_ppp_modal').modal('hide');
                        $("#add_pppoe_form").trigger("reset");
                        dataTable_ppp_user.draw(false);
                    } else if (msg == "FinAlreadyExists") {
                        $.dialog({
                            title: 'Xəta !',
                            content: 'Bu FİN kod artıq istifadə olunub.',
                            type: 'red'
                        });
                    } else if (msg == "FinLengthError") {
                        $.dialog({
                            title: 'Xəta !',
                            content: 'FİN kod 7 simvol olmalıdır.',
                            type: 'red'
                        });
                    } else if (msg == "PhoneEmpty") {
                        $.dialog({
                            title: 'Xəta !',
                            content: 'Telefon nömrəsi daxil edilməlidir.',
                            type: 'red'
                        });
                    } else {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Əməliyyat zamanı xəta baş verdi.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }
                }
            });
        }
    });
});




$(document).on('click', '.user_deaktiv', function () {
    var deaktiv_ppp_user_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Deaktiv etmək istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'deaktiv_ppp_user_id': deaktiv_ppp_user_id
                    },
                    success: function (cavab) {
                        dataTable_ppp_user.draw(false);
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});



$(document).on('click', '.costumer_deaktiv', function () {
    var deaktiv_ppp_costumer_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Aktiv etmək istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'deaktiv_ppp_costumer_id': deaktiv_ppp_costumer_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});



$(document).on('click', '.costumer_aktiv', function () {
    var aktiv_ppp_costumer_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Aktiv etmək istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'aktiv_ppp_costumer_id': aktiv_ppp_costumer_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});


$(document).on('click', '.user_aktiv', function () {
    var aktiv_ppp_user_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Deaktiv etmək istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'aktiv_ppp_user_id': aktiv_ppp_user_id
                    },
                    success: function (cavab) {
                        dataTable_ppp_user.draw(false);
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});





$(document).on('click', '.user_deaktiv_p', function () {
    var deaktiv_ppp_user_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Aktiv etmək istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'deaktiv_ppp_user_id': deaktiv_ppp_user_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});


$(document).on('click', '.user_aktiv_p', function () {
    var aktiv_ppp_user_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Deaktiv etmək istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'aktiv_ppp_user_id': aktiv_ppp_user_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});


$(document).on('click', '.user_delete', function () {
    var ppp_user_delete_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Silmək  istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'ppp_user_delete_id': ppp_user_delete_id
                    },
                    success: function (cavab) {
                        dataTable_ppp_user.draw(false);
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});



$(document).on('click', '.user_refresh', function () {
    var ppp_user_refresh_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Abonent onlayndan silinəcək və digər problemlər yoxdursa yenidən onlayn olacaq.',
        buttons: {
            Yenilə: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'ppp_user_refresh_id': ppp_user_refresh_id
                    },
                    success: function (cavab) {
                        // DataTable varsa yenilə, yoxdursa səhifəni yenilə
                        if (typeof dataTable_ppp_user !== 'undefined') {
                            dataTable_ppp_user.draw(false);
                        } else {
                            // ppp_user_profile.php səhifəsində səhifəni yenilə
                            setTimeout(function () {
                                location.reload();
                            }, 3000); // 3 saniyə sonra yenilə ki, disconnect işini bitirsin
                        }
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});




$(document).on('click', '.costumer_delete', function () {
    var ppp_costumer_delete_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Silmək  istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'ppp_costumer_delete_id': ppp_costumer_delete_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});




$(document).on('click', '.tarfic_delete', function () {
    var trafic_delete_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Silmək  istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'trafic_delete_id': trafic_delete_id
                    },
                    success: function (cavab) {
                        window.location.reload()
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});



$(document).on('click', '.ppp_user_edit', function () {
    var ppp_user_edit_id = $(this).attr('id');
    $('input[name=ppp_edit_mikrotik]').val(ppp_user_edit_id);
    $.ajax({
        type: 'post',
        url: 'api/core/core.php',
        data: { "ppp_user_edit_id": ppp_user_edit_id },
        success: function (msg) {
            var ppp_edit_isitfadeci = JSON.parse(msg);


            if (ppp_edit_isitfadeci.day == "" || ppp_edit_isitfadeci.day == " ") {
                $('#ppp_edit_day').val("");
                $('#ppp_edit_day').attr('placeholder', 'gün-ay-il (09-11-2021)');
                $('#ppp_edit_day').prop('readonly', false);
            } else {

                $('#ppp_edit_day').val(ppp_edit_isitfadeci.day);
            }



            $('#ppp_edit_hours').val(ppp_edit_isitfadeci.saat);
            $('#ppp_edit_login').val(ppp_edit_isitfadeci.login);
            $('#ppp_edit_name').val(ppp_edit_isitfadeci.ad);
            $('#bonus_gun').val(ppp_edit_isitfadeci.bonus);
            $('#ppp_edit_phone').val(ppp_edit_isitfadeci.telefon);
            $('#ppp_edit_adress').val(ppp_edit_isitfadeci.adress);
            $('#ppp_edit_mounth').val(ppp_edit_isitfadeci.borc_mounth);
            $('#ppp_edit_amount').val(ppp_edit_isitfadeci.borc_amount);
            $('#ppp_edit_ats_select').val(ppp_edit_isitfadeci.zone_id);
            $('#ppp_ip_adress').val(ppp_edit_isitfadeci.ip);
            $('#ppp_edit_pass').val(ppp_edit_isitfadeci.pass);
            $('#ppp_edit_balance').val(ppp_edit_isitfadeci.balance);
            $('#ppp_edit_comment').val(ppp_edit_isitfadeci.comment);
            $("div.edit_profile select").val(ppp_edit_isitfadeci.profile);

            $('#ppp_edit_status').val(ppp_edit_isitfadeci.stat);
            // Set FİN kod and Ş.V seriya fields
            $('#ppp_edit_fin').val(ppp_edit_isitfadeci.fin);
            $('#ppp_edit_sv').val(ppp_edit_isitfadeci.sv);
            $('#ppp_edit_stabil').val(ppp_edit_isitfadeci.stabil);

            // Set current zone and ip_type for modal populate
            $('#ppp_edit_zone_current').val(ppp_edit_isitfadeci.pool_zone_id); // This is ppp_zones.id
            $('#ppp_edit_ip_type_current').val(ppp_edit_isitfadeci.ip_type);
        }
    });
});



$(document).on('click', '.costumer_user_edit', function () {
    var costumer_user_edit_id = $(this).attr('id');
    $('input[name=costumer_edit_id]').val(costumer_user_edit_id);
    $.ajax({
        type: 'post',
        url: 'api/core/core.php',
        data: { "costumer_user_edit_id": costumer_user_edit_id },
        success: function (msg) {
            var costumer_edit_isitfadeci = JSON.parse(msg);

            $('#costumer_edit_name').val(costumer_edit_isitfadeci.ad);
            $('#costumer_edit_mail').val(costumer_edit_isitfadeci.mail);
            $('#costumer_edit_pass').val(costumer_edit_isitfadeci.parol);
            $('#costumer_edit_profile option[value=' + costumer_edit_isitfadeci.status + ']').attr('selected', 'selected');
            $('#costumer_edit_zone option[value=' + costumer_edit_isitfadeci.zone + ']').attr('selected', 'selected');

            // İzinleri yükle
            $('.edit-permission-checkbox').prop('checked', false);
            if (costumer_edit_isitfadeci.permissions) {
                var permissions = costumer_edit_isitfadeci.permissions.split(',');
                permissions.forEach(function (perm) {
                    $('.edit-permission-checkbox[value="' + perm + '"]').prop('checked', true);
                });
            }


        }
    });
});



$(document).on('click', '.ppp_packet_edit', function () {
    var ppp_packet_edit_id = $(this).attr('id');

    $('input[name=ppp_packet_edit_mikrotik]').val(ppp_packet_edit_id);
    $.ajax({
        type: 'post',
        url: 'api/core/core.php',
        data: { "ppp_packet_edit_id": ppp_packet_edit_id },
        success: function (msg) {

            var packed_edit_isitfadeci = JSON.parse(msg);

            $('#ppp_packet_name_edit').val(packed_edit_isitfadeci.name);
            $('#ppp_packet_rate_edit').val(packed_edit_isitfadeci.rate);
            $('#ppp_packet_price_edit').val(packed_edit_isitfadeci.price);

        }
    });
});




// add pppoe  packet

$(function () {
    $("#add_pppoe_packet_form").validate({
        errorClass: "authError",
        rules: {
            ppp_packet_name: {
                required: true
            },

            ppp_packet_rate: {
                required: true
            },
            ppp_packet_price: {
                required: true
            }

        },
        messages: {

            ppp_packet_name: {
                required: "Ad daxil edin",

            },
            ppp_packet_rate: {
                required: "Sürət daxil edin",

            },
            ppp_packet_price: {
                required: "Qiymət daxil edin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_pppoe_packet_form').serialize(),
                success: function (msg) {
                    if (msg == 1) {
                        $('#add_ppp_packet_modal').modal('hide');

                        $("#add_pppoe_packet_form").trigger("reset");
                        $('#example_ppp_paket').DataTable().ajax.reload();
                    } else {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Əməliyyat zamanı xəta baş verdi.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});




$(function () {
    $("#edit_pppoe_form").validate({
        errorClass: "authError",
        rules: {
            ppp_edit_name: {
                required: true
            },
            ppp_edit_phone: {
                required: true
            },
            ppp_edit_pass: {
                required: true
            },
            ppp_edit_profile: {
                required: true
            },
            ppp_edit_ats_select: {
                required: true
            },
            ppp_edit_zone_select: {
                required: true
            },
            ppp_edit_ip_type: {
                required: true
            },
            ppp_edit_fin: {
                required: true,
                minlength: 7,
                maxlength: 7
            }
        },
        messages: {
            ppp_edit_name: {
                required: "Boşluğu doldurun",
            },
            ppp_edit_phone: {
                required: "Boşluğu doldurun",
            },
            ppp_edit_pass: {
                required: "Boşluğu doldurun",
            },
            ppp_edit_profile: {
                required: "Boşluğu doldurun",
            },
            ppp_edit_ats_select: {
                required: "Zəhmət olmasa zona seçin",
            },
            ppp_edit_zone_select: {
                required: "Zəhmət olmasa pool seçin",
            },
            ppp_edit_ip_type: {
                required: "Zəhmət olmasa IP tipini seçin",
            },
            ppp_edit_fin: {
                required: "FİN kodu daxil edin!",
                minlength: "Zəhmət olmasa 7 simvol daxil edin.",
                maxlength: "Zəhmət olmasa 7 simvol daxil edin."
            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#edit_pppoe_form').serialize(),
                success: function (msg) {
                    if (msg == 1) {
                        $('#ppp_edit_modal').modal('hide');
                        $("#ppp_edit_add_day").val("0");
                        dataTable_ppp_user.draw(false);
                    } else if (msg == "FinAlreadyExists") {
                        $.dialog({
                            title: 'Xəta !',
                            content: 'Bu FİN kod artıq istifadə olunub.',
                            type: 'red'
                        });
                    } else if (msg == "FinLengthError") {
                        $.dialog({
                            title: 'Xəta !',
                            content: 'FİN kod 7 simvol olmalıdır.',
                            type: 'red'
                        });
                    } else if (msg == "PhoneEmpty") {
                        $.dialog({
                            title: 'Xəta !',
                            content: 'Telefon nömrəsi daxil edilməlidir.',
                            type: 'red'
                        });
                    } else {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Əməliyyat zamanı xəta baş verdi.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }
                }
            });
        }
    });
});







$(function () {
    $("#edit_costumer_form").validate({
        errorClass: "authError",
        rules: {
            costumer_edit_name: {
                required: true
            },
            costumer_edit_mail: {
                required: true
            },

            costumer_edit_pass: {
                required: true
            },

            costumer_edit_zone: {
                required: true
            },
            costumer_edit_profile: {
                required: true
            }

        },
        messages: {

            costumer_edit_name: {
                required: "Boşluğu doldurun",

            },
            costumer_edit_mail: {
                required: "Boşluğu doldurun",

            },
            costumer_edit_pass: {
                required: "Boşluğu doldurun",

            },
            costumer_edit_zone: {
                required: "Boşluğu doldurun",

            },
            costumer_edit_profile: {
                required: "Boşluğu doldurun",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#edit_costumer_form').serialize(),
                success: function (msg) {
                    if (msg == 1) {
                        window.location = window.location.pathname;
                    } else {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Əməliyyat zamanı xəta baş verdi.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});



$(function () {
    $("#add_costumer_form").validate({
        errorClass: "authError",
        rules: {
            costumer_add_name: {
                required: true
            },
            costumer_add_mail: {
                required: true
            },

            costumer_add_pass: {
                required: true
            },
            costumer_add_zone: {
                required: true
            },

            costumer_add_profile: {
                required: true
            }

        },
        messages: {

            costumer_add_name: {
                required: "Boşluğu doldurun",

            },
            costumer_add_mail: {
                required: "Boşluğu doldurun",

            },
            costumer_add_pass: {
                required: "Boşluğu doldurun",

            },
            costumer_add_profile: {
                required: "Boşluğu doldurun",

            },
            costumer_add_zone: {
                required: "Boşluğu doldurun",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_costumer_form').serialize(),
                success: function (msg) {
                    if (msg == 1) {
                        window.location = window.location.pathname;
                    } else {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Əməliyyat zamanı xəta baş verdi.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});





$(function () {
    $("#edit_pppoe_packet_form").validate({
        errorClass: "authError",
        rules: {
            ppp_packet_name_edit: {
                required: true
            },
            ppp_packet_rate_edit: {
                required: true
            },

            ppp_packet_price_edit: {
                required: true
            }

        },
        messages: {

            ppp_packet_name_edit: {
                required: "Boşluğu doldurun",

            },
            ppp_packet_rate_edit: {
                required: "Boşluğu doldurun",

            },
            ppp_packet_price_edit: {
                required: "Boşluğu doldurun",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#edit_pppoe_packet_form').serialize(),
                success: function (msg) {
                    if (msg == 1) {
                        $('#ppp_packet_edit_modal').modal('hide');
                        $('#example_ppp_paket').DataTable().ajax.reload();
                    } else {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Əməliyyat zamanı xəta baş verdi.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });
});
















$(document).on('click', '.ppp_packet_delete', function () {
    var ppp_packet_delete_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Silmək  istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'ppp_packet_delete_id': ppp_packet_delete_id
                    },
                    success: function (cavab) {
                        $('#example_ppp_paket').DataTable().ajax.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});



// add hotspot 

$(function () {
    $("#add_hotspot_form").validate({
        errorClass: "authError",
        rules: {
            hotspot_name: {
                required: true
            },
            hotspot_pass: {
                required: true
            },

            hotspot_user: {
                required: true
            },

            hotspot_paket: {
                required: true
            }

        },
        messages: {

            hotspot_name: {
                required: "Ad daxil edin",

            },
            hotspot_pass: {
                required: "İstifadeci adı daxil edin",

            },
            hotspot_user: {
                required: "Xidmət seçin",

            },
            hotspot_paket: {
                required: "Paket seçin",

            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'api/core/core.php',
                data: $('#add_hotspot_form').serialize(),
                success: function (msg) {
                    if (msg == 1) {
                        $('#add_hotspot_modal').modal('hide');
                        $("#add_hotspotoe_form").trigger("reset");
                        $('#example_hotspot_user').DataTable().ajax.reload();
                    } else {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Əməliyyat zamanı xəta baş verdi.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }

                }
            });
        }
    });









    $(document).on('click', '.hotspot_delete', function () {
        var hotspot_user_delete_id = $(this).attr('id');
        $.confirm({
            title: false,
            content: 'Silmək  istədiyinizə əminsiniz ?',
            buttons: {
                Bəlİ: function () {
                    $.ajax({
                        type: 'post',
                        url: 'api/core/core.php',
                        data: {
                            'hotspot_user_delete_id': hotspot_user_delete_id
                        },
                        success: function (cavab) {
                            $('#example_hotspot_user').DataTable().ajax.reload();
                        }
                    });
                },
                Xeyr: function () { }
            }
        });
    });



    $(document).on('click', '.hotspot_deaktiv', function () {
        var deaktiv_hotspot_user_id = $(this).attr('id');
        $.confirm({
            title: false,
            content: 'Dektiv etmək istədiyinizə əminsiniz ?',
            buttons: {
                Bəlİ: function () {
                    $.ajax({
                        type: 'post',
                        url: 'api/core/core.php',
                        data: {
                            'deaktiv_hotspot_user_id': deaktiv_hotspot_user_id
                        },
                        success: function (cavab) {
                            $('#example_hotspot_user').DataTable().ajax.reload();
                        }
                    });
                },
                Xeyr: function () { }
            }
        });
    });



    $(document).on('click', '.hotspot_aktiv', function () {
        var aktiv_hotspot_user_id = $(this).attr('id');
        $.confirm({
            title: false,
            content: 'Aktiv etmək istədiyinizə əminsiniz ?',
            buttons: {
                Bəlİ: function () {
                    $.ajax({
                        type: 'post',
                        url: 'api/core/core.php',
                        data: {
                            'aktiv_hotspot_user_id': aktiv_hotspot_user_id
                        },
                        success: function (cavab) {
                            $('#example_hotspot_user').DataTable().ajax.reload();
                        }
                    });
                },
                Xeyr: function () { }
            }
        });
    });

});

$('#home_search_button').click(function () {
    var home_search = $('#home_search_code').val();
    $.ajax({
        type: 'post',
        url: 'api/core/core.php',
        data: {
            'home_search': home_search
        },

        success: function (msg) {

            if (msg == 2) {
                $('#home_search_code').val("");
                $.dialog({
                    title: 'Xəta !',
                    closeIcon: true,
                    icon: 'fas fa-check',
                    content: 'Abonent kodu yalnışdır !',
                    type: 'red',
                    typeAnimated: true,
                });
            } else if (msg == 1) {

                window.location.href = "ppp_user_profile.php?id=" + home_search;
            }

        }
    })





});





$(function () {
    $(".setting_pass_form").validate({
        errorClass: "authError",
        rules: {
            user_new_pass: {
                required: true,
                minlength: 6
            },
            user_new_pass_confrim: {
                required: true,
                equalTo: "#user_new_pass"
            }
        },
        messages: {
            user_new_pass: {
                required: "Yeni şifrə daxil edin",
                minlength: "Minimum 6 xaraketdən ibarət olmalıdır",
            },
            user_new_pass_confrim: {
                required: "Şifrənin təkrarını daxil edin",
                equalTo: "Şifrələr eyni deyil !",
            }
        },
        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: '../core/main/funksiya.php',
                data: $('.setting_pass_form').serialize(),
                success: function (msg) {
                    if (msg == 1) {
                        $.dialog({
                            title: 'Uğurlu !',
                            closeIcon: true,
                            icon: 'fas fa-check',
                            content: 'Şifrə yeniləndi.Yenidən daxil olun',
                            type: 'green',
                            typeAnimated: true,
                        });
                        window.setTimeout(function () {
                            window.location.href = '../logout.php';
                        }, 3000);
                    } else if (msg == 2) {
                        $.dialog({
                            title: 'Xəta !',
                            closeIcon: true,
                            icon: 'fas fa-exclamation-circle',
                            content: 'Yenidən yoxlayın.',
                            type: 'red',
                            typeAnimated: true,
                        });
                    }
                }
            });
        }
    });
});







$(document).on('click', '.iptv_delete', function () {
    var iptv_delete_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Silmək  istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'iptv_delete_id': iptv_delete_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});





$(document).on('click', '.cabel_tv_delete', function () {
    var cabel_tv_delete_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Silmək  istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'cabel_tv_delete_id': cabel_tv_delete_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});



$(document).on('click', '.sip_delete', function () {
    var sip_delete_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Silmək  istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'sip_delete_id': sip_delete_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});





$(document).on('click', '.static_ip_delete', function () {
    var static_ip_delete_id = $(this).attr('id');
    $.confirm({
        title: false,
        content: 'Silmək  istədiyinizə əminsiniz ?',
        buttons: {
            Bəlİ: function () {
                $.ajax({
                    type: 'post',
                    url: 'api/core/core.php',
                    data: {
                        'static_ip_delete_id': static_ip_delete_id
                    },
                    success: function (cavab) {
                        location.reload();
                    }
                });
            },
            Xeyr: function () { }
        }
    });
});










// Reseet pass 

$(function () {
    $("#reset_pass").validate({
        errorClass: "authError",
        rules: {
            mail: {
                required: true,
                email: true
            }

        },
        messages: {

            mail: {
                required: "E-poçt ünvanı daxil edin",
                email: "Düzgün e-poçt daxil edin",

            }
        },

        submitHandler: function (form) {
            $.ajax({
                type: 'post',
                url: 'core/main/reset.php',
                data: $('#reset_pass').serialize(),
                success: function (msg) {
                    if (msg == 3) {
                        $('#reset_alert').html('<div class="alert alert-danger mg-b-0" role="alert">E-mail  mövcud deyil ! <button type="button" class="close" data-dismiss="alert" aria-label="Close"> <span aria-hidden="true">&times;</span> </button></div>');
                    }
                    if (msg == 1) {
                        $('#reset_alert').html("");
                        $("#reset_pass").trigger('reset');
                        $("#basicModal").modal('hide');
                        Swal.fire({
                            position: 'top-end',
                            icon: 'success',
                            title: 'E-poçt ünvanına şifrə göndərildi.',
                            showConfirmButton: false,
                            timer: 3000
                        });
                        setInterval(function () {
                            window.history.back();
                        }, 3000);
                    }

                    if (msg == 2) {

                        $("#reset_pass").trigger('reset');
                        $("#basicModal").modal('hide');

                        Swal.fire({
                            position: 'top-end',
                            icon: 'warning',
                            title: 'Xəta baş verdi yenidən yoxlayın.',
                            showConfirmButton: false,
                            timer: 3500

                        });




                    }


                }

            });
        }
    });
});
var btn_top = $('#top_button');

$(window).scroll(function () {
    if ($(window).scrollTop() > 300) {
        btn_top.addClass('show');
    } else {
        btn_top.removeClass('show');
    }
});

btn_top.on('click', function (e) {
    e.preventDefault();
    $('html, body').animate({ scrollTop: 0 }, '300');
});

