<?php

// Mongolian versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['{{ company.name }}-д тавтай морилно уу', <<<'MD'
Сайн байна уу, {{ client.first_name }}

{{ company.name }}-д бүртгэл үүсгэсэнд баярлалаа.

Үйлчилгээ захиалах, нэхэмжлэх төлөх, тусламжийн багтай холбогдохын тулд хүссэн үедээ нэвтэрнэ үү:

[Бүртгэл рүүгээ орох]({{ client_area_url }})

Хэрэв та нууц үгээ хараахан тохируулаагүй бол нэвтрэх хуудсан дээрх "Нууц үгээ мартсан уу?"-г ашиглана уу.

Баярлалаа,
{{ company.name }}
MD],
    'client.two_factor_code' => ['{{ company.name }}-д нэвтрэх таны код', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Таны нэвтрэх код: **{{ code }}**

Энэ код 10 минутын турш хүчинтэй. Энэ кодыг хэнд ч бүү хэлээрэй: манай ажилтнууд үүнийг хэзээ ч асуухгүй.

Хэрэв та нэвтрэхийг оролдоогүй бол нууц үгээ яаралтай солино уу.

{{ company.name }}
MD],
    'order.confirmation' => ['Захиалга #{{ order.number }} хүлээн авлаа', <<<'MD'
Сайн байна уу, {{ client.first_name }}

**#{{ order.number }}** захиалга өгсөнд баярлалаа. Нийт дүн: **{{ order.total }}**.

[Нэхэмжлэх {{ invoice.number }}-ийг төлөх]({{ invoice.url }})

Төлбөр орж ирмэгц бид таны үйлчилгээг тохируулж, дэлгэрэнгүй мэдээллийг имэйлээр илгээнэ.

Баярлалаа,
{{ company.name }}
MD],
    'invoice.created' => ['Нэхэмжлэх {{ invoice.number }} бэлэн боллоо', <<<'MD'
Сайн байна уу, {{ client.first_name }}

**{{ invoice.total }}** дүнтэй **{{ invoice.number }}** нэхэмжлэх бэлэн боллоо. Төлөх эцсийн хугацаа: **{{ invoice.due_date }}**.

[Нэхэмжлэхийг харах, төлөх]({{ invoice.url }})

Баярлалаа,
{{ company.name }}
MD],
    'invoice.credit_note' => ['Нэхэмжлэх {{ invoice.number }}-ийн залруулгын нэхэмжлэл {{ credit_note.number }}', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Бид **{{ invoice.number }}** нэхэмжлэхэд **{{ credit_note.total }}** дүнтэй **{{ credit_note.number }}** залруулгын нэхэмжлэл гаргалаа.

{{ credit_note.note }}

[Нэхэмжлэх болон залруулгын нэхэмжлэлийг харах]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['Нэхэмжлэх {{ invoice.number }}-ийн төлбөрийг хүлээн авлаа', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Бид **{{ invoice.number }}** нэхэмжлэхийн төлбөрийг тань хүлээн авлаа. Баярлалаа!

[Нэхэмжлэхийг харах]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['Нэхэмжлэх {{ invoice.number }} {{ charge_date }}-нд автоматаар төлөгдөнө', <<<'MD'
Сайн байна уу, {{ client.first_name }}

**{{ charge_date }}**-нд бид **{{ invoice.number }}** нэхэмжлэхийн **{{ invoice.balance }}**-ийг таны {{ payment_method.name }}-аас татна. Танд юу ч хийх шаардлагагүй.

[Нэхэмжлэхийг харах]({{ invoice.url }})

Өөр карт ашиглах эсвэл автомат төлбөрийг унтраахын тулд [Төлбөрийн аргууд]({{ payment_methods_url }}) хэсгийг нээнэ үү.

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['Нэхэмжлэх {{ invoice.number }}-ийн төлбөрийг таны {{ payment_method.name }}-аас татаж чадсангүй', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Өнөөдөр бид **{{ invoice.number }}** нэхэмжлэхийн **{{ invoice.balance }}**-ийг таны {{ payment_method.name }}-аас татахыг оролдсон боловч амжилтгүй боллоо: {{ failure }} Таны данснаас ямар ч мөнгө хасагдаагүй.

{{ next_try }}

[Нэхэмжлэхийг төлөх]({{ invoice.url }})

Хэрэв таны картын хугацаа дууссан эсвэл шинэ дугаартай болсон бол [шинэ картаа нэмнэ үү]({{ payment_methods_url }}), бид дараагийн оролдлогод түүнийг ашиглана.

{{ company.name }}
MD],
    'payment.method_expiring' => ['Таны хадгалсан картын хугацаа удахгүй дуусна', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Таны сунгалтыг автоматаар төлдөг {{ payment_method.name }}-ийн хугацаа {{ payment_method.expires }}-ийн эцэст дуусна.

Сунгалтууд тань автоматаар төлөгдсөөр байхын тулд [шинэ картаа нэмнэ үү]({{ payment_methods_url }}).

{{ company.name }}
MD],
    'invoice.reminder' => ['Сануулга: нэхэмжлэх {{ invoice.number }}-ийн төлөх хугацаа хэтэрсэн', <<<'MD'
Сайн байна уу, {{ client.first_name }}

**{{ invoice.balance }}** дүнтэй **{{ invoice.number }}** нэхэмжлэхийн төлөх эцсийн хугацаа {{ invoice.due_date }} байсан бөгөөд одоог хүртэл төлөгдөөгүй байна.

[Одоо төлөх]({{ invoice.url }})

Төлөгдөөгүй нэхэмжлэхтэй үйлчилгээг хэдэн хоногийн дараа түр түдгэлзүүлдэг. Хэрэв та аль хэдийн төлсөн бол энэ имэйлийг үл тоомсорлоно уу.

{{ company.name }}
MD],
    'service.welcome' => ['Таны {{ service.product }} бэлэн боллоо', <<<'MD'
Сайн байна уу, {{ client.first_name }}

**{{ service.domain }}**-д зориулсан таны **{{ service.product }}** тохируулагдаж, ашиглахад бэлэн боллоо.

- Хэрэглэгчийн нэр: {{ service.username }}
- Сервер: {{ service.server }}

Таны нууц үг болон удирдлагын самбарт нэг товшилтоор нэвтрэх холбоос харилцагчийн хувийн кабинетэд байна:

[Үйлчилгээний дэлгэрэнгүйг нээх]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['Үйлчилгээг түр түдгэлзүүлсэн: {{ service.domain }}', <<<'MD'
Сайн байна уу, {{ client.first_name }}

**{{ service.domain }}**-д зориулсан таны **{{ service.product }}**-ийг түр түдгэлзүүллээ.

Шалтгаан: {{ reason }}

Төлөгдөөгүй нэхэмжлэхээ төлбөл үйлчилгээ автоматаар дахин идэвхжинэ. Тусламж хэрэгтэй бол энэ имэйлд хариу бичнэ үү.

[Бүртгэл рүүгээ орох]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['Таны багц одоо {{ plan.new }} боллоо', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Таны **{{ service.domain }}** үйлчилгээ **{{ plan.old }}** багцаас **{{ plan.new }}** багц руу шилжлээ.

Дараагийн сунгалтаас эхлэн та {{ plan.amount }} ({{ plan.cycle }}) төлнө. {{ plan.note }}

[Үйлчилгээгээ харах]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['Үйлчилгээ дахин идэвхжлээ: {{ service.domain }}', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Сайн мэдээ: **{{ service.domain }}**-д зориулсан таны **{{ service.product }}** дахин идэвхжлээ.

{{ company.name }}
MD],
    'service.resources_changed' => ['Таны серверийн нөөц өөрчлөгдлөө', <<<'MD'
Сайн байна уу, {{ client.first_name }}

**{{ service.domain }}**-ийн нөөц өөрчлөгдлөө:

{{ resources.list }}

Дараагийн сунгалтаас эхлэн та эдгээр нэмэлтэд {{ resources.amount }} ({{ resources.cycle }}) төлнө. {{ resources.note }}

[Үйлчилгээгээ харах]({{ service.url }})

{{ company.name }}
MD],
    'service.server_change' => ['Таны {{ service.domain }} сервер дээр өөрчлөлт гарлаа', <<<'MD'
Сайн байна уу, {{ client.first_name }}

{{ change.text }}

- Хэзээ: {{ change.time }}
- IP хаягаас: {{ change.ip }}

Хэрэв та үүнийг хийгээгүй бол харилцагчийн хувийн кабинетын нууц үгээ яаралтай сольж, бидэнтэй холбогдоно уу.

[Үйлчилгээгээ харах]({{ service.url }})

{{ company.name }}
MD],
    'service.dedicated_ready' => ['Таны {{ service.product }} сервер бэлэн боллоо', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Таны **{{ service.product }}** тусгай сервер бэлэн боллоо.

- IP хаяг: {{ machine.ip }}
- Бүх IP хаяг: {{ machine.ips }}
- Нэвтрэх нэр: {{ machine.username }}
- Хостын нэр: {{ machine.hostname }}
- Байршил: {{ machine.location }}

Таны нууц үг харилцагчийн хувийн кабинетэд байна. Анх нэвтэрсний дараа нууц үгээ солино уу.

[Сервероо нээх]({{ service.url }})

{{ company.name }}
MD],
    'domain.registered' => ['Таны {{ domain.name }} домэйн бүртгэгдлээ', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Сайн мэдээ: **{{ domain.name }}** одоо таны нэр дээр **{{ domain.expires_at }}** хүртэл бүртгэгдлээ.

Нэрийн серверүүд: {{ domain.nameservers }}

Домэйн интернетийн бүх газар ажиллаж эхлэхэд хэдэн цаг шаардагдаж магадгүй.

[Домэйнээ удирдах]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['{{ domain.name }}-ийн шилжүүлэг эхэллээ', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Бид **{{ domain.name }}**-ийг манай руу шилжүүлэх ажлыг эхлүүллээ. Шилжүүлэг ихэвчлэн 5-7 хоног үргэлжилдэг.

Таны одоогийн бүртгэгч шилжүүлгийг батлахыг хүсэж танд имэйл илгээж магадгүй. Үүнийг баталснаар шилжүүлэг илүү хурдан хийгдэнэ.

[Шилжүүлгийг хянах]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['Таны {{ domain.name }} домэйн сунгагдлаа', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Баярлалаа! **{{ domain.name }}** сунгагдлаа. Одоо түүний хугацаа **{{ domain.expires_at }}**-нд дуусна.

[Домэйнээ удирдах]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }}-ийн хугацаа {{ days_left }} хоногийн дараа дуусна', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Таны **{{ domain.name }}** домэйны хугацаа **{{ domain.expires_at }}**-нд дуусах бөгөөд автоматаар сунгагдахгүй.

Хэрэв та үүнийг хадгалахыг хүсвэл одоо сунгана уу. Хугацаа нь дууссан домэйн ажиллахаа больж, өөр хэн нэгэн бүртгүүлж магадгүй.

[{{ domain.name }}-ийг сунгах]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[Хүсэлт #{{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Бид таны хүсэлтийг хүлээн авсан бөгөөд аль болох хурдан хариу өгнө.

**{{ ticket.subject }}** · {{ ticket.department }}

[Хүсэлтээ харах]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[Хүсэлт #{{ ticket.number }}] Шинэ хариу: {{ ticket.subject }}', <<<'MD'
Сайн байна уу, {{ client.first_name }}

{{ reply.author }} таны хүсэлтэд хариу бичлээ:

{{ reply.message }}

[Хүсэлтийг харах, хариу бичих]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['{{ company.name }} дээр аюулгүй байдлын шинэ асуудал илэрлээ', <<<'MD'
Сайн байна уу, {{ staff.name }}

Өнөө шөнийн сайтын төлөвийн шалгалтаар таны анхаарал шаардсан шинэ зүйл илэрлээ:

{{ issues }}

Таны аюулгүй байдлын оноо: **100-аас {{ score }}**.

[Сайтын төлөвийг нээх]({{ admin_url }})

Та аюулгүй байдлын асуудлыг харж, засах эрхтэй тул энэ имэйлийг хүлээн авч байна. Нууц үг болон файлын агуулгыг эдгээр имэйлд хэзээ ч оруулдаггүй.
MD],
    'admin.new_order' => ['{{ client.name }}-ийн шинэ захиалга #{{ order.number }}', <<<'MD'
{{ client.name }} ({{ client.email }}) **{{ order.total }}** дүнтэй **#{{ order.number }}** захиалга өглөө.

[Захиалгыг нээх]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['Шинэ хүсэлт #{{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
{{ client.name }} {{ ticket.department }} хэлтэст хүсэлт нээлээ:

**{{ ticket.subject }}**

{{ reply.message }}

[Одоо хариу бичих]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['Харилцагч #{{ ticket.number }} хүсэлтэд хариу бичлээ', <<<'MD'
{{ client.name }} **{{ ticket.subject }}** хүсэлтэд хариу бичлээ:

{{ reply.message }}

[Одоо хариу бичих]({{ admin_url }})
MD],
    'quote.sent' => ['{{ company.name }}-ээс ирсэн таны үнийн санал {{ quote.number }}', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Таны **{{ quote.number }}** үнийн санал: {{ quote.subject }}

Нийт: **{{ quote.total }}**, хүчинтэй хугацаа: {{ quote.valid_until }} хүртэл.

[Үнийн саналыг харах, зөвшөөрөх]({{ quote.url }})

Асуулт байна уу? Энэ имэйлд хариу бичихэд л хангалттай.

{{ company.name }}
MD],
    'admin.quote_accepted' => ['{{ client.name }} үнийн санал {{ quote.number }}-ийг зөвшөөрлөө', <<<'MD'
{{ client.name }} **{{ quote.total }}** дүнтэй **{{ quote.number }}** ({{ quote.subject }}) үнийн саналыг зөвшөөрлөө.

Үүнд зориулж {{ invoice.number }} нэхэмжлэх үүсгэлээ.

[Үнийн саналыг нээх]({{ admin_url }})
MD],
    'admin.resources_failed' => ['Үйлчилгээ #{{ service.id }} дээр нөөцийг тохируулж чадсангүй', <<<'MD'
Сервер {{ client.name }}-ийн **{{ service.product }}** (үйлчилгээ #{{ service.id }})-ийн шинэ нөөцийг хүлээж авсангүй. Харилцагч эдгээрийн төлбөрийг төлсөн.

Юу амжилтгүй болсон: {{ error }}

Үйлчилгээг нээгээд **Дахин оролдох**-ыг дарна уу. Эсвэл сервер дээр нөөцийг гараар тохируулаад **Дууссан гэж тэмдэглэх**-ийг дарна уу.

[Үйлчилгээг нээх]({{ admin_url }})
MD],
    'admin.resources_by_hand' => ['Үйлчилгээ #{{ service.id }} дээр нөөцийг гараар тохируулна уу', <<<'MD'
{{ client.name }} **{{ service.product }}** (үйлчилгээ #{{ service.id }})-ийн нөөцийг өөрчиллөө. Энэ үйлчилгээнд нөөцийг тохируулдаг серверийн модуль байхгүй тул сервер дээр гараар тохируулна уу:

{{ resources.list }}

Дараа нь үйлчилгээний хуудсан дээр **Дууссан гэж тэмдэглэх**-ийг дарна уу.

[Үйлчилгээг нээх]({{ admin_url }})
MD],
    'admin.machine_install' => ['{{ client.name }}-д зориулж {{ machine.name }} дээр үйлдлийн систем суулгана уу', <<<'MD'
{{ client.name }} **{{ service.product }}** (үйлчилгээ #{{ service.id }})-ийн төлбөрийг төллөө.

- Машин: {{ machine.name }}
- Байршил: {{ machine.location }}, тавиур {{ machine.rack }} {{ machine.rack_unit }}
- Систем: {{ machine.default_os }}

Системийг суулгаад үйлчилгээний хуудсан дээр **Сервер бэлэн боллоо**-г дарна уу. Үүний дараа харилцагч имэйл хүлээн авна.

[Үйлчилгээг нээх]({{ admin_url }})
MD],
    'admin.machine_wipe' => ['{{ machine.name }}-ийг дахин зарахаас өмнө цэвэрлэнэ үү', <<<'MD'
Үйлчилгээ #{{ service.id }} дууссан тул **{{ machine.name }}** ({{ machine.location }}) унтарсан бөгөөд цэвэрлэгээ хүлээж байна.

Бүх дискийн өгөгдлийг устгаад **Цэвэрлэгээг баталгаажуулах**-ыг дарна уу. Түүнээс өмнө энэ машиныг дахин хэзээ ч зарахгүй.

[Машиныг нээх]({{ admin_url }})
MD],
    'admin.machine_alert' => ['{{ machine.name }}: {{ alert.title }}', <<<'MD'
{{ alert.message }}

[Машиныг нээх]({{ admin_url }})
MD],
    'affiliate.commission' => ['Та урилгаас {{ commission.amount }} шимтгэл авлаа', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Таны {{ company.name }}-д урьсан хүн нэхэмжлэх төлсөн тул та **{{ commission.amount }}** шимтгэл авлаа.

Энэ дүн {{ commission.available_on }}-нд ашиглах боломжтой болно. Дараа нь та үүнийг хэтэвч рүүгээ шилжүүлж болно.

[Түншийн бүртгэлээ харах]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['Таны {{ item.name }}-ийн лицензийн түлхүүр', <<<'MD'
Сайн байна уу, {{ client.first_name }}

**{{ item.name }}**-ийг худалдан авсанд баярлалаа. Таны лицензийн түлхүүр:

**{{ license.key }}**

Суулгахын тулд Nuvabill-ийн админ хэсгээ нээж, **Маркетплейс** руу орж, {{ item.name }}-ийг олоод, түлхүүрээ буулгаж **Суулгах** дээр дарна уу.

Түлхүүр нь таны анх суулгасан сайттай холбогдоно. Та үүнийг бүртгэлээсээ өөр сайт руу шилжүүлж болно.

[Бүртгэлээ нээх]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
Сайн байна уу, {{ client.first_name }}

Бид **{{ item.name }} {{ item.version }}**-ийг шалгалаа. Үр дүн: {{ review.outcome }}.

{{ review.message }}

[Хөгжүүлэгчийн бүртгэлээ нээх]({{ developer_url }})

{{ company.name }}
MD],
];
